#!/usr/bin/env python3
"""HTTP probe runner for Keel #378 (Login Answers). Lab-only; not shipped.

Usage: probes.py <phase> [site]
Writes results to $KEEL_LA_LAB/results/<phase>-<site>.json
"""
import html
import http.client
import json
import os
import re
import statistics
import subprocess
import sys
import time
import urllib.parse
import uuid
from http.cookies import SimpleCookie

LAB = os.environ.get('KEEL_LA_LAB', '/tmp/keel-login-answers-lab')
KEEL = os.environ.get('KEEL_LA_KEEL', os.path.abspath(os.path.join(os.path.dirname(__file__), '../../..')))
PORT = {'s71': 8871, 's64': 8864, 'ms': 8872, 'sq': 8873}
WPCLI = [os.environ.get('KEEL_LA_WPCLI', 'wp'), '--allow-root']
PW = open(f'{LAB}/state/.pw').read().strip()
os.makedirs(f'{LAB}/results', exist_ok=True)


def wp(site, *args, url=None, check=True):
    cmd = WPCLI + [f'--path={LAB}/sites/{site}'] + ([f'--url={url}'] if url else []) + list(args)
    r = subprocess.run(cmd, capture_output=True, text=True, env={**os.environ, 'WP_CLI_PHP_ARGS': '-d memory_limit=512M'})
    if check and r.returncode != 0:
        raise RuntimeError(f'wp {args}: {r.stderr[-400:]}')
    return r.stdout.strip()


def keel(site, on, url=None):
    wp(site, 'plugin', 'activate' if on else 'deactivate', 'keel-defaults', url=url, check=False)


def setting(site, value, url=None):
    return wp(site, 'eval-file', f'{LAB}/set-setting.php', 'uniform_login_answers', value, url=url)


def network(site, value):
    return wp(site, 'eval-file', f'{LAB}/set-setting.php', 'network', 'uniform_login_answers', value)


def req(site, method, path, data=None, headers=None, cookies=None, host=None):
    rid = uuid.uuid4().hex[:12]
    c = http.client.HTTPConnection('127.0.0.1', PORT[site], timeout=120)
    h = {'Host': host or f'{site}.test', 'X-Lab-Rid': rid}
    if headers:
        h.update(headers)
    body = None
    if data is not None:
        body = urllib.parse.urlencode(data, doseq=True) if not isinstance(data, (bytes, str)) else data
        h['Content-Type'] = 'application/x-www-form-urlencoded'
    if cookies:
        h['Cookie'] = '; '.join(f'{k}={v}' for k, v in cookies.items())
    t0 = time.perf_counter()
    c.request(method, path, body, h)
    r = c.getresponse()
    raw = r.read()
    dt = time.perf_counter() - t0
    setc = [v for k, v in r.getheaders() if k.lower() == 'set-cookie']
    names = sorted({s.split('=', 1)[0] for s in setc})
    jar = {}
    for s in setc:
        k, v = s.split(';', 1)[0].split('=', 1)
        jar[k] = v
    return {
        'rid': rid, 'status': r.status, 'location': r.getheader('Location'),
        'cookie_names': names, 'jar': jar, 'body': raw.decode('utf-8', 'replace'), 'secs': dt,
    }


def login(site, log, pwd, extra=None, path='/wp-login.php', host=None):
    d = {'log': log, 'pwd': pwd, 'wp-submit': 'Log In', 'redirect_to': f'http://{host or site + ".test"}/wp-admin/'}
    if extra:
        d.update(extra)
    return req(site, 'POST', path, d, host=host)


def lostpw(site, user_login, redirect_to=None, path='/wp-login.php?action=lostpassword', host=None):
    d = {'user_login': user_login, 'wp-submit': 'Get New Password', 'redirect_to': redirect_to or ''}
    return req(site, 'POST', path, d, host=host)


def login_error(body):
    m = re.search(r'<div id="login_error"[^>]*>(.*?)</div>', body, re.S)
    return m.group(1).strip() if m else None


def user_login_value(body):
    m = re.search(r'<input[^>]*id="user_login"[^>]*>', body)
    if not m:
        return None
    v = re.search(r'value="([^"]*)"', m.group(0))
    return v.group(1) if v else None


def focus(body):
    m = re.search(r'function wp_attempt_focus\(\)\{(.*?)\}\n', body, re.S)
    if not m:
        m = re.search(r'wp_attempt_focus\(\) \{(.*?)\n', body, re.S)
    s = m.group(1) if m else ''
    return 'user_pass' if 'user_pass' in s else ('user_login' if 'user_login' in s else None)


def shaped(r, typed=None):
    b = r['body']
    if typed:
        for v in sorted({typed, html.escape(typed, quote=True), html.escape(typed.strip(), quote=True)}, key=len, reverse=True):
            if v:
                b = b.replace(v, '{TYPED}')
    return {'status': r['status'], 'location': r['location'], 'cookies': r['cookie_names'], 'body': b}


def same(*rs):
    return all(json.dumps(x, sort_keys=True) == json.dumps(rs[0], sort_keys=True) for x in rs)


def first_diff(a, b):
    if a['status'] != b['status'] or a['location'] != b['location'] or a['cookies'] != b['cookies']:
        return {k: (a[k], b[k]) for k in ('status', 'location', 'cookies') if a[k] != b[k]}
    for i, (x, y) in enumerate(zip(a['body'], b['body'])):
        if x != y:
            return {'at': i, 'a': a['body'][max(0, i - 80):i + 120], 'b': b['body'][max(0, i - 80):i + 120]}
    return {'len': (len(a['body']), len(b['body']))}


def logged(site, name, rid):
    p = f'{LAB}/state/{site}/{name}'
    if not os.path.exists(p):
        return []
    return [json.loads(l) for l in open(p) if json.loads(l).get('rid') == rid]


def flag(site, name, on):
    p = f'{LAB}/state/{site}/{name}'
    if on:
        open(p, 'w').close()
    elif os.path.exists(p):
        os.remove(p)


def lab_nonce(site, action, cookies, prefix='', host=None):
    """A nonce wp_create_nonce() makes for the signed-in user (lab instrument)."""
    r = req(site, 'GET', f'{prefix}/?lab_nonce={urllib.parse.quote(action)}', cookies=cookies, host=host)
    assert r['body'].startswith('NONCE:'), (r['status'], r['body'][:200])
    return r['body'][6:].strip()


# Capability refusals, by screen. Network Admin has two: core's gate on the whole of Network Admin
# (reached first, before admin_init) and Keel's own check in its save handler behind it.
CAP_DENIED = {'options': ('not allowed to manage options',),
              'network': ('Sorry, you are not allowed to access this page.', 'do not have permission to set network policy')}
NONCE_DENIED = 'The link you followed has expired'


def denial(r, kind):
    """Which check refused a save: 'capability', 'nonce' or 'other'."""
    if r['status'] == 403 and any(m in r['body'] for m in CAP_DENIED[kind]):
        return 'capability'
    if NONCE_DENIED in r['body']:
        return 'nonce'
    return 'other'


def admin_cookies(site, user='labadmin', host=None, path_prefix=''):
    r = login(site, user, PW, path=f'{path_prefix}/wp-login.php', host=host)
    assert r['status'] == 302, (user, r['status'], login_error(r['body']))
    return r['jar']


LOGIN_INPUTS = [('unknown_username', 'nobody', 'x-wrong'), ('unknown_email', 'nobody@example.test', 'x-wrong'),
                ('real_wrong', 'riley', 'x-wrong'), ('real_email_wrong', 'riley@example.test', 'x-wrong')]
LOST_INPUTS = [('unknown_username', 'nobody'), ('unknown_email', 'nobody@example.test'),
               ('real_username', 'riley'), ('real_email', 'riley@example.test')]


def capture(site, host=None, prefix=''):
    """Login and lost-password responses for the probe inputs, with side-channel logs."""
    out = {'login': {}, 'lost': {}}
    for key, log, pwd in LOGIN_INPUTS:
        r = login(site, log, pwd, path=f'{prefix}/wp-login.php', host=host)
        out['login'][key] = {
            'typed': log, 'shape': shaped(r, log), 'error': login_error(r['body']),
            'user_login': user_login_value(r['body']), 'focus': focus(r['body']),
            'failed_hook': [x['username'] for x in logged(site, 'login-failed.log', r['rid'])],
        }
    for key, ul in LOST_INPUTS:
        r = lostpw(site, ul, path=f'{prefix}/wp-login.php?action=lostpassword', host=host)
        out['lost'][key] = {'status': r['status'], 'location': r['location'], 'error': login_error(r['body']),
                            'mails': sum(x['recipients'] for x in logged(site, 'mail.log', r['rid']))}
    return out


def uniform_login(cap):
    shapes = [v['shape'] for v in cap['login'].values()]
    return same(*shapes), [v['error'] for v in cap['login'].values()]


def uniform_lost(cap):
    vals = [(v['status'], v['location'], v['error']) for v in cap['lost'].values()]
    return len(set(vals)) == 1, vals


R = {}


def record(pid, cell, status, evidence):
    R.setdefault(pid, {})[cell] = {'result': status, 'evidence': evidence}
    print(f'{pid:4} [{cell}] {status}: {json.dumps(evidence)[:300]}', flush=True)


# ---------------------------------------------------------------- single site

def single(site):
    cell = site
    flag(site, 'mail-fail', False)
    flag(site, 'lockout', False)

    # Baseline: Keel inactive.
    keel(site, False)
    base = capture(site)
    base_extra = {
        'empty_both': shaped(login(site, '', '')),
        'empty_pwd_real': shaped(login(site, 'riley', ''), 'riley'),
        'empty_pwd_unknown': shaped(login(site, 'nobody', ''), 'nobody'),
        'lost_empty': lostpw(site, ''),
        'rp_bad_real': req(site, 'GET', '/wp-login.php?action=rp&key=badkey&login=riley'),
        'rp_bad_unknown': req(site, 'GET', '/wp-login.php?action=rp&key=badkey&login=nobody'),
    }
    good_base = [login(site, 'riley', PW), login(site, 'riley@example.test', PW)]

    keel(site, True)
    print(setting(site, 'yes'))
    on = capture(site)

    # P5 / P6 / P7 / P8 / P32
    ok, errs = uniform_login(on)
    record('P5', cell, 'PASS' if ok else 'FAIL', {'identical_after_masking_typed': ok, 'error_text': errs[0]})
    p6 = same(on['login']['real_email_wrong']['shape'], on['login']['unknown_email']['shape'])
    record('P6', cell, 'PASS' if p6 else 'FAIL', {'real_email_vs_unknown_email_identical': p6})
    p7 = all(v['user_login'] == html.escape(v['typed'], quote=True) and v['focus'] == 'user_pass' for v in on['login'].values())
    record('P7', cell, 'PASS' if p7 else 'FAIL', {k: (v['user_login'], v['focus']) for k, v in on['login'].items()})
    e = on['login']['real_wrong']['error'] or ''
    p8 = all(t not in e for t in ('nobody', 'riley', '@example')) and 'username, email address or password is incorrect' in e and e.count('action=lostpassword') == 1
    record('P8', cell, 'PASS' if p8 else 'FAIL', {'error': e})
    # P32 in a real browser: the accessibility tree Chromium hands a screen reader, focus after load, submits per Enter.
    logs = [log for _, log, _ in LOGIN_INPUTS]
    setting(site, 'no')
    ax_off = a11y(site, logs)
    setting(site, 'yes')
    ax_on = a11y(site, logs)
    masked = lambda v, log: v['form_aria'].replace(log, '<typed>')
    p32 = {
        'error_is_text': all(v['error_text'] and 'username, email address or password is incorrect' in v['error_text'] for v in ax_on.values()),
        'error_tree_same_for_every_input': len({v['error_aria'] for v in ax_on.values()}) == 1,
        'form_tree_same_for_every_input': len({masked(v, log) for log, v in ax_on.items()}) == 1,
        'focus_same_for_every_input': {v['focused_id'] for v in ax_on.values()} == {'user_pass'},
        'announced_as_core_announces_it': {json.dumps(v['error_attrs']) for v in ax_on.values()} == {json.dumps(v['error_attrs']) for v in ax_off.values()},
        'one_submit_per_enter': all(v['posts_per_enter'] == 1 for v in list(ax_on.values()) + list(ax_off.values())),
    }
    record('P32', cell, 'PASS' if all(p32.values()) else 'FAIL', dict(p32, error_tree=ax_on[logs[0]]['error_aria'], error_attrs=ax_on[logs[0]]['error_attrs'],
                                                                   focus_off={k: v['focused_id'] for k, v in ax_off.items()},
                                                                   note='accessibility tree, not a screen reader'))

    # P13: wp_login_failed once per failure with the submitted username.
    p13 = all(v['failed_hook'] == [v['typed']] for v in on['login'].values())
    record('P13', cell, 'PASS' if p13 else 'FAIL', {k: v['failed_hook'] for k, v in on['login'].items()})

    # P16 / P17
    ok, vals = uniform_lost(on)
    p16 = ok and vals[0][0] == 302 and (vals[0][1] or '').endswith('wp-login.php?checkemail=confirm')
    record('P16', cell, 'PASS' if p16 else 'FAIL', {'responses': vals[:1], 'all_same': ok})
    mails = {k: v['mails'] for k, v in on['lost'].items()}
    p17 = mails == {'unknown_username': 0, 'unknown_email': 0, 'real_username': 1, 'real_email': 1}
    record('P17', cell, 'PASS' if p17 else 'FAIL', mails)

    # P18: mail failure for a real account.
    flag(site, 'mail-fail', True)
    r = lostpw(site, 'riley')
    flag(site, 'mail-fail', False)
    # The failure must really have happened on this request: one mail attempt, to one recipient, that the hook failed.
    sent = logged(site, 'mail.log', r['rid'])
    failed_here = len(sent) == 1 and sent[0]['recipients'] == 1 and sent[0]['fail'] is True
    p18 = failed_here and r['status'] == 302 and r['location'] == vals[0][1] and 'could not be sent' not in r['body']
    record('P18', cell, 'PASS' if p18 else 'FAIL', {'status': r['status'], 'location': r['location'], 'mail_log_for_request': sent, 'mail_failed_on_this_request': failed_here})

    # P19: allow_password_reset false.
    r = lostpw(site, 'noreset')
    p19 = r['status'] == 302 and r['location'] == vals[0][1] and not logged(site, 'mail.log', r['rid'])
    record('P19', cell, 'PASS' if p19 else 'FAIL', {'status': r['status'], 'location': r['location'], 'mails': len(logged(site, 'mail.log', r['rid']))})

    # P20: redirect_to.
    locs = {}
    for target in ('/thanks/', 'https://evil.example/'):
        locs[target] = [lostpw(site, ul, redirect_to=target)['location'] for _, ul in LOST_INPUTS]
    p20 = all(len(set(v)) == 1 for v in locs.values()) and 'evil.example' not in (locs['https://evil.example/'][0] or '')
    record('P20', cell, 'PASS' if p20 else 'FAIL', {k: v[0] for k, v in locs.items()})

    # P21 / P22
    r = lostpw(site, '')
    p21 = login_error(r['body']) == login_error(base_extra['lost_empty']['body']) and r['status'] == base_extra['lost_empty']['status']
    record('P21', cell, 'PASS' if p21 else 'FAIL', {'error': login_error(r['body'])})
    rp = {k: req(site, 'GET', p) for k, p in (('real', '/wp-login.php?action=rp&key=badkey&login=riley'), ('unknown', '/wp-login.php?action=rp&key=badkey&login=nobody'))}
    p22a = rp['real']['location'] == base_extra['rp_bad_real']['location'] and rp['unknown']['location'] == base_extra['rp_bad_unknown']['location']
    # The whole reset-link flow, with the setting off and on: the redirect, the follow-up screen it leads to
    # (with the rp cookie), an expired key and a valid key.
    setting(site, 'no')
    rp_off = rp_flow(site)
    setting(site, 'yes')
    rp_on = rp_flow(site)
    p22b = rp_off == rp_on
    expected = rp_on['bad_riley']['followup_location'].endswith('error=invalidkey') and rp_on['bad_nobody']['followup_location'].endswith('error=invalidkey') \
        and rp_on['expired_riley']['followup_location'].endswith('error=expiredkey') and rp_on['valid_riley']['followup_form']
    record('P22', cell, 'PASS' if p22a and p22b and expected else 'FAIL', {'first_redirect_same_as_keel_inactive': p22a, 'flow_off_equals_on': p22b,
                                                                         'flow_on': rp_on, 'reaches_expected_screens': expected})

    # P11: empty fields.
    e_both = shaped(login(site, '', ''))
    e_r = shaped(login(site, 'riley', ''), 'riley')
    e_u = shaped(login(site, 'nobody', ''), 'nobody')
    p11 = same(e_r, e_u) and login_error(e_both['body']) == login_error(base_extra['empty_both']['body']) and login_error(e_r['body']) == login_error(base_extra['empty_pwd_real']['body'])
    record('P11', cell, 'PASS' if p11 else 'FAIL', {'empty_both': login_error(e_both['body']), 'empty_pwd': login_error(e_r['body']), 'real_vs_unknown_same': same(e_r, e_u)})

    # P9: case and spaces (this cell's engine).
    ref = on['login']['unknown_username']['shape']
    p9 = {}
    for typed in ('CASEY', ' riley '):
        rr = login(site, typed, 'x-wrong')
        p9[typed] = {'same_as_unknown': same(shaped(rr, typed), ref), 'error': login_error(rr['body'])[:60]}
        if not p9[typed]['same_as_unknown']:
            p9[typed]['diff'] = first_diff(ref, shaped(rr, typed))
    record('P9', cell, 'PASS' if all(v['same_as_unknown'] for v in p9.values()) else 'FAIL', p9)

    # P10: hostile input.
    hostile = {'markup': '<script>alert(1)</script>"\'', 'long': 'a' * 1000, 'non_utf8': b'\xff\xfe\xc3('}
    p10 = {}
    for k, v in hostile.items():
        if isinstance(v, bytes):
            body = b'log=' + urllib.parse.quote_from_bytes(v).encode() + b'&pwd=x&wp-submit=Log+In'
            rr = req(site, 'POST', '/wp-login.php', body)
        else:
            rr = login(site, v, 'x-wrong')
        val = user_login_value(rr['body'])
        p10[k] = {'status': rr['status'], 'raw_script_echoed': '<script>alert(1)' in rr['body'],
                  'error_same': login_error(rr['body']) == on['login']['real_wrong']['error'], 'value_len': len(val or '')}
    ok10 = all(x['status'] == 200 and not x['raw_script_echoed'] and x['error_same'] for x in p10.values())
    record('P10', cell, 'PASS' if ok10 else 'FAIL', p10)

    # P12: interim login and a wp_login_form()-shaped post.
    inter = [shaped(login(site, log, pwd, extra={'interim-login': '1'}, path='/wp-login.php?interim-login=1'), log) for _, log, pwd in LOGIN_INPUTS]
    front = [shaped(login(site, log, pwd, extra={'rememberme': 'forever', 'redirect_to': f'http://{site}.test/sample-page/'}), log) for _, log, pwd in LOGIN_INPUTS]
    record('P12', cell, 'PASS' if same(*inter) and same(*front) else 'FAIL', {'interim_identical': same(*inter), 'wp_login_form_identical': same(*front)})

    # P14: another plugin's lockout.
    flag(site, 'lockout', True)
    lk = [login_error(login(site, log, pwd)['body']) for _, log, pwd in LOGIN_INPUTS]
    flag(site, 'lockout', False)
    record('P14', cell, 'PASS' if all(re.sub('</?p>', '', x or '') == 'Locked out for 20 minutes.' for x in lk) else 'FAIL', {'errors': lk})

    # P15: correct credentials.
    good = [login(site, 'riley', PW), login(site, 'riley@example.test', PW)]
    hooked = wp(site, 'eval', 'echo has_action("wp_login","keel_defaults_screen_login_password") ? "yes" : "no";')
    p15 = all(g['status'] == 302 and any(n.startswith('wordpress_logged_in') for n in g['cookie_names']) for g in good) and \
        [g['location'] for g in good] == [g['location'] for g in good_base] and hooked == 'yes'
    record('P15', cell, 'PASS' if p15 else 'FAIL', {'status': [g['status'] for g in good], 'location': good[0]['location'],
                                                    'matches_baseline_location': [g['location'] for g in good] == [g['location'] for g in good_base],
                                                    'breach_recheck_hooked': hooked})

    # P3: setting off == core.
    print(setting(site, 'no'))
    off = capture(site)
    diffs = {}
    for k in base['login']:
        a, b = base['login'][k], off['login'][k]
        if (a['error'], a['user_login'], a['focus'], a['shape']['status']) != (b['error'], b['user_login'], b['focus'], b['shape']['status']):
            diffs[k] = (a['error'], b['error'])
    body_identical = all(same(base['login'][k]['shape'], off['login'][k]['shape']) for k in base['login'])
    lost_same = all((base['lost'][k]['status'], base['lost'][k]['location'], base['lost'][k]['error']) == (off['lost'][k]['status'], off['lost'][k]['location'], off['lost'][k]['error']) for k in base['lost'])
    bd = None if body_identical else first_diff(base['login']['real_wrong']['shape'], off['login']['real_wrong']['shape'])
    record('P3', cell, 'PASS' if not diffs and lost_same and body_identical else ('PASS*' if not diffs and lost_same else 'FAIL'),
           {'messages_refill_focus_status_match': not diffs, 'lost_match': lost_same, 'full_body_identical': body_identical, 'first_body_diff': bd,
            'core_messages': [base['login'][k]['error'][:70] for k in base['login']]})
    print(setting(site, 'yes'))

    # P23: not-doing stays undone.
    p23 = {}
    wp(site, 'option', 'update', 'users_can_register', '1')
    # Baseline here is Keel active with only this setting off, so other Keel
    # defaults (XML-RPC method gating) don't count against it. Remote
    # publishing is switched on for the probe so wp.getUsersBlogs authenticates.
    wp(site, 'eval-file', f'{LAB}/set-setting.php', 'xmlrpc_allow_remote_publishing', 'yes')
    setting(site, 'no')
    reg_b = req(site, 'POST', '/wp-login.php?action=register', {'user_login': 'newbie', 'user_email': 'riley@example.test'})
    xb = xmlrpc(site)
    rb = rest_basic(site)
    ub = user_screens(site)
    setting(site, 'yes')
    reg_k = req(site, 'POST', '/wp-login.php?action=register', {'user_login': 'newbie', 'user_email': 'riley@example.test'})
    xk = xmlrpc(site)
    rk = rest_basic(site)
    uk = user_screens(site)
    wp(site, 'eval-file', f'{LAB}/set-setting.php', 'xmlrpc_allow_remote_publishing', 'no')
    wp(site, 'option', 'update', 'users_can_register', '0')
    p23['registration'] = login_error(reg_b['body']) == login_error(reg_k['body'])
    p23['registration_error'] = (login_error(reg_k['body']) or '')[:90]
    p23['xmlrpc'] = xb == xk
    p23['xmlrpc_answers'] = xk
    # REST Basic auth counts only if a correct application password really authenticates (positive control).
    p23['rest_positive_control'] = rk['riley_correct'][0] == 200 and rb['riley_correct'][0] == 200
    p23['rest_basic'] = p23['rest_positive_control'] and rb == rk
    p23['rest_answers'] = rk
    # Profile and Add New: duplicate username and email errors, setting off vs on.
    p23['user_screens'] = ub == uk and all(v for v in uk.values())
    p23['user_screen_errors'] = uk
    ok23 = p23['registration'] and p23['xmlrpc'] and p23['rest_basic'] and p23['user_screens']
    record('P23', cell, 'PASS' if ok23 else 'FAIL', p23)
    return base, on


def xmlrpc(site):
    out = {}
    for who in ('nobody', 'riley'):
        x = f'<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value>{who}</value></param><param><value>x-wrong</value></param></params></methodCall>'
        r = req(site, 'POST', '/xmlrpc.php', x, headers={'Content-Type': 'text/xml'})
        m = re.search(r'<string>(.*?)</string>', r['body'])
        out[who] = (r['status'], m.group(1) if m else r['body'][:80])
    return out


APP_PASSWORDS = {}


def rest_basic(site):
    """/wp/v2/users/me with Basic auth: a correct application password (the positive control), a wrong one, an unknown user."""
    import base64
    # One application password per run, made fresh, so a rebuilt site never meets a stale one.
    if site not in APP_PASSWORDS:
        wp(site, 'user', 'application-password', 'delete', 'riley', '--all', check=False)
        APP_PASSWORDS[site] = wp(site, 'user', 'application-password', 'create', 'riley', 'lab', '--porcelain').strip()
    good = APP_PASSWORDS[site]
    out = {}
    for label, who, pw in (('riley_correct', 'riley', good), ('riley_wrong', 'riley', 'abcd efgh ijkl mnop qrst uvwx'),
                           ('nobody', 'nobody', 'abcd efgh ijkl mnop qrst uvwx')):
        tok = base64.b64encode(f'{who}:{pw}'.encode()).decode()
        r = req(site, 'GET', '/?rest_route=/wp/v2/users/me', headers={'Authorization': f'Basic {tok}'})
        try:
            j = json.loads(r['body'])
            out[label] = (r['status'], j.get('code') or ('user ' + str(j.get('id'))))
        except Exception:
            out[label] = (r['status'], r['body'][:60])
    return out


def a11y(site, logs):
    """Run a11y.js (Playwright) against this site's login form for each typed login."""
    out = subprocess.run(['node', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'a11y.js'), str(PORT[site]), f'{site}.test'] + logs,
                         capture_output=True, text=True, timeout=300)
    if out.returncode:
        raise RuntimeError(out.stderr[-2000:])
    return json.loads(out.stdout)


def form_fields(body, form_id):
    """Every named input of one form, as the browser would post it (text, hidden and checked boxes)."""
    m = re.search(r'<form[^>]*id="' + form_id + r'"[^>]*>(.*?)</form>', body, re.S)
    out = {}
    for tag in re.findall(r'<input[^>]*>', m.group(1) if m else ''):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        kind = re.search(r'type="([^"]+)"', tag)
        if kind and kind.group(1) in ('checkbox', 'radio', 'submit', 'button') and 'checked' not in tag:
            continue
        val = re.search(r'value="([^"]*)"', tag)
        out[name.group(1)] = html.unescape(val.group(1)) if val else ''
    return out


def screen_errors(body):
    return [re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', e)).strip() for e in re.findall(r'<div[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)</div>', body, re.S)]


def user_screens(site):
    """Duplicate-account errors on Users > Add New (administrator) and Profile (riley)."""
    out = {}
    ck = admin_cookies(site)
    page = req(site, 'GET', '/wp-admin/user-new.php', cookies=ck)['body']
    f = form_fields(page, 'createuser')
    f.update({'user_login': 'riley', 'email': 'riley@example.test', 'pass1': 'Lab-Long-Unique-Passphrase-9431', 'pass2': 'Lab-Long-Unique-Passphrase-9431', 'pw_weak': 'on', 'role': 'subscriber'})
    out['add_new_duplicate'] = screen_errors(req(site, 'POST', '/wp-admin/user-new.php', f, cookies=ck)['body'])
    # User-edit: an administrator gives eddy riley's email address.
    eddy = wp(site, 'user', 'get', 'eddy', '--field=ID')
    page = req(site, 'GET', f'/wp-admin/user-edit.php?user_id={eddy}', cookies=ck)['body']
    f = form_fields(page, 'your-profile')
    f['email'] = 'riley@example.test'
    out['user_edit_email_taken'] = screen_errors(req(site, 'POST', '/wp-admin/user-edit.php', f, cookies=ck)['body'])
    # Profile: riley asks for eddy's address. Core shows no error here; it answers with a redirect and
    # (for a free address) a confirmation mail, so the comparison is the status and Location.
    ck_r = admin_cookies(site, 'riley')
    page = req(site, 'GET', '/wp-admin/profile.php', cookies=ck_r)['body']
    f = form_fields(page, 'your-profile')
    f['email'] = 'eddy@example.test'
    r = req(site, 'POST', '/wp-admin/profile.php', f, cookies=ck_r)
    out['profile_email_taken'] = [r['status'], r['location']] + screen_errors(r['body'])
    wp(site, 'user', 'meta', 'delete', 'riley', '_new_email', check=False)
    return out


def rp_flow(site):
    """The reset-link screens: what a visitor sees for a bad key (real and unknown login), an expired key and a valid key."""
    def follow(path):
        r1 = req(site, 'GET', path)
        loc = urllib.parse.urlsplit(r1['location'] or '')
        r2 = req(site, 'GET', loc.path + ('?' + loc.query if loc.query else ''), cookies=r1['jar']) if r1['location'] else None
        return {'status': r1['status'], 'location': r1['location'], 'cookie_names': r1['cookie_names'],
                'followup_status': r2['status'] if r2 else None, 'followup_location': (r2['location'] if r2 else None) or '',
                'followup_error': login_error(r2['body']) if r2 else None,
                'followup_form': bool(r2) and 'id="resetpassform"' in r2['body']}
    out = {'bad_riley': follow('/wp-login.php?action=rp&key=badkey&login=riley'),
           'bad_nobody': follow('/wp-login.php?action=rp&key=badkey&login=nobody')}
    key = wp(site, 'eval', 'echo get_password_reset_key( get_user_by( "login", "riley" ) );')
    wp(site, 'eval', 'global $wpdb; $u = get_user_by( "login", "riley" ); $p = explode( ":", $u->user_activation_key, 2 ); '
                     '$wpdb->update( $wpdb->users, array( "user_activation_key" => "1:" . $p[1] ), array( "ID" => $u->ID ) ); clean_user_cache( $u );')
    out['expired_riley'] = follow(f'/wp-login.php?action=rp&key={key}&login=riley')
    key = wp(site, 'eval', 'echo get_password_reset_key( get_user_by( "login", "riley" ) );')
    out['valid_riley'] = follow(f'/wp-login.php?action=rp&key={key}&login=riley')
    wp(site, 'eval', 'global $wpdb; $u = get_user_by( "login", "riley" ); $wpdb->update( $wpdb->users, array( "user_activation_key" => "" ), array( "ID" => $u->ID ) ); clean_user_cache( $u );')
    return out


# ---------------------------------------------------------------- phases

def phase_single(site):
    single(site)


def phase_fresh(site):
    """P1 fresh activation; P31 Site Health/help; P33 uninstall."""
    cell = site
    keel(site, False)
    wp(site, 'option', 'delete', 'keel_settings', check=False)
    wp(site, 'option', 'delete', 'keel_settings_data_version', check=False)
    keel(site, True)
    stored = wp(site, 'eval', 'echo json_encode( (get_option("keel_settings") ?: array())["uniform_login_answers"] ?? null ), " ", keel_defaults_get("uniform_login_answers");')
    ck = admin_cookies(site)
    page = req(site, 'GET', '/wp-admin/options-general.php?page=keel', cookies=ck)['body']
    m = re.search(r'<input[^>]*name="keel_settings\[uniform_login_answers\]"[^>]*>', page)
    shown_on = bool(m and 'checked' in m.group(0))
    record('P1', cell, 'PASS' if stored == '"yes" yes' and shown_on else 'FAIL', {'stored_resolved': stored, 'control': m.group(0) if m else None})

    # P31: Site Health in both states, Help tab detail, readme.
    items = ['registration', 'profile', 'reset-link', 'response time', 'attempt limiting', 'application password', 'XML-RPC', 'WooCommerce']
    sh = {}
    for v in ('yes', 'no'):
        setting(site, v)
        j = json.loads(wp(site, 'eval', 'wp_set_current_user(1); echo wp_json_encode( keel_defaults_site_health_login_answers() );'))
        sh[v] = {'status': j['status'], 'missing': [i for i in items if i.lower() not in j['description'].lower()]}
    setting(site, 'yes')
    page = req(site, 'GET', '/wp-admin/options-general.php?page=keel', cookies=ck)['body']
    help_missing = [i for i in items if i.lower() not in page.lower().split('login answers', 1)[-1].lower()]
    readme = open(KEEL + '/readme.txt').read().lower()
    readme_missing = [i for i in items if i.lower() not in readme.split('why does a failed login', 1)[-1][:2500]]
    ok = sh['yes']['status'] == 'good' and sh['no']['status'] == 'recommended' and not sh['yes']['missing'] and not sh['no']['missing'] and not readme_missing and not help_missing
    record('P31', cell, 'PASS' if ok else 'FAIL', {'site_health': sh, 'help_missing': help_missing, 'readme_missing': readme_missing})

    # P33: run uninstall.php directly (never `wp plugin uninstall`: the plugin dir is a symlink to the repo).
    wp(site, 'eval', f'define("WP_UNINSTALL_PLUGIN","keel-defaults/keel.php"); include "{KEEL}/uninstall.php";')
    rows = wp(site, 'db', 'query', "SELECT option_name FROM wp_options WHERE option_name LIKE 'keel%' OR option_value LIKE '%uniform_login_answers%'", '--skip-column-names')
    record('P33', cell, 'PASS' if not rows.strip() else 'FAIL', {'rows_after_uninstall': rows.split()})
    keel(site, False)
    keel(site, True)


def phase_upgrade(site):
    """P2: a site on 0.9.2 updated in place, no settings save."""
    cell = site
    plug = f'{LAB}/sites/{site}/wp-content/plugins/keel-defaults'
    keel(site, False)
    wp(site, 'option', 'delete', 'keel_settings', check=False)
    wp(site, 'option', 'delete', 'keel_settings_data_version', check=False)
    os.remove(plug)
    os.symlink(os.environ.get('KEEL_LA_KEEL_092', LAB + '/keel-092'), plug)
    keel(site, True)
    ver_before = wp(site, 'plugin', 'get', 'keel-defaults', '--field=version')
    before = wp(site, 'option', 'get', 'keel_settings', '--format=json')
    os.remove(plug)
    os.symlink(KEEL, plug)
    ver_after = wp(site, 'plugin', 'get', 'keel-defaults', '--field=version')
    after = wp(site, 'option', 'get', 'keel_settings', '--format=json')
    resolved = wp(site, 'eval', 'echo keel_defaults_get("uniform_login_answers");')
    cap = capture(site)
    ok, _ = uniform_login(cap)
    ok2, _ = uniform_lost(cap)
    p2 = 'uniform_login_answers' not in before and before == after and resolved == 'yes' and ok and ok2
    record('P2', cell, 'PASS' if p2 else 'FAIL', {'version_before': ver_before, 'version_after': ver_after, 'key_in_stored_before': 'uniform_login_answers' in before,
                                                  'stored_unchanged_by_update': before == after, 'resolved': resolved, 'P5_uniform': ok, 'P16_uniform': ok2})


def phase_editor(site):
    """P30 single site: an editor cannot change the setting; an administrator can, through the same request."""
    keel(site, True)
    setting(site, 'yes')
    stored = wp(site, 'option', 'get', 'keel_settings', '--format=json')
    # The settings form as it posts with Login Answers unticked: every other stored value, this key absent.
    cur = json.loads(wp(site, 'eval', '$o=array(); foreach (array_keys(keel_defaults_schema()) as $k) { $o[$k]=keel_defaults_get($k); } echo wp_json_encode($o);'))
    payload = {'option_page': 'keel_settings_group', 'action': 'update'}
    for k, v in cur.items():
        if k != 'uniform_login_answers' and isinstance(v, str) and v != 'no':
            payload[f'keel_settings[{k}]'] = v
    resolved = lambda: wp(site, 'eval', 'echo keel_defaults_get("uniform_login_answers");')
    ev = {}
    # Allowed: an administrator's save with a real nonce turns it off.
    ck_a = admin_cookies(site)
    r = req(site, 'POST', '/wp-admin/options.php', dict(payload, _wpnonce=lab_nonce(site, 'keel_settings_group-options', ck_a)), cookies=ck_a)
    ev['admin_status'] = r['status']
    ev['admin_changed_it'] = resolved() == 'no'
    wp(site, 'option', 'update', 'keel_settings', stored, '--format=json')
    # Denied: the editor's own valid nonce, so only the capability check can refuse it.
    ck_e = admin_cookies(site, 'eddy')
    r = req(site, 'POST', '/wp-admin/options.php', dict(payload, _wpnonce=lab_nonce(site, 'keel_settings_group-options', ck_e)), cookies=ck_e)
    ev['editor_status'] = r['status']
    ev['editor_refused_by'] = denial(r, 'options')
    ev['editor_left_it_on'] = resolved() == 'yes' and wp(site, 'option', 'get', 'keel_settings', '--format=json') == stored
    ok = ev['admin_changed_it'] and ev['editor_status'] == 403 and ev['editor_refused_by'] == 'capability' and ev['editor_left_it_on']
    record('P30', site, 'PASS' if ok else 'FAIL', ev)


def phase_timing(site, n=200):
    """P24: measure; nothing is claimed."""
    keel(site, True)
    setting(site, 'yes')
    out = {}
    for key, log, pwd in (('login_unknown', 'nobody', 'x-wrong'), ('login_real_wrong', 'riley', 'x-wrong')):
        t = [login(site, log, pwd)['secs'] * 1000 for _ in range(n)]
        out[key] = (round(statistics.median(t), 1), round(statistics.pstdev(t), 1))
    for key, ul in (('lost_unknown', 'nobody'), ('lost_real', 'riley')):
        t = [lostpw(site, ul)['secs'] * 1000 for _ in range(n)]
        out[key] = (round(statistics.median(t), 1), round(statistics.pstdev(t), 1))
    record('P24', site, 'PASS', {'median_ms_and_stdev': out, 'n': n})


def phase_woo(site):
    """P25: WooCommerce My Account login, Keel inactive vs active (setting on)."""
    def woo_login(log):
        page = req(site, 'GET', '/?page_id=' + wp(site, 'option', 'get', 'woocommerce_myaccount_page_id'))
        nonce = re.search(r'name="woocommerce-login-nonce" value="([^"]+)"', page['body'])
        jar = page['jar']
        r = req(site, 'POST', '/?page_id=' + wp(site, 'option', 'get', 'woocommerce_myaccount_page_id'),
                {'username': log, 'password': 'x-wrong', 'woocommerce-login-nonce': nonce.group(1) if nonce else '', '_wp_http_referer': '/', 'login': 'Log in'}, cookies=jar)
        m = re.search(r'notice-banner__content">(.*?)</div>', r['body'], re.S) or re.search(r'<ul class="woocommerce-error"[^>]*>(.*?)</ul>', r['body'], re.S)
        return re.sub(r'\s+', ' ', re.sub('<[^>]+>', '', m.group(1))).strip()[:140] if m else f'(no notice; status {r["status"]})'
    setting(site, 'yes')  # before WooCommerce: the helper fires admin_init, which WooCommerce can't take from the CLI
    wp(site, 'plugin', 'activate', 'woocommerce')
    keel(site, False)
    base = {log: woo_login(log) for _, log, _ in LOGIN_INPUTS}
    keel(site, True)
    on = {log: woo_login(log) for _, log, _ in LOGIN_INPUTS}
    wp(site, 'plugin', 'deactivate', 'woocommerce')
    record('P25', site, 'PASS' if base == on and len(set(on.values())) > 1 else 'FAIL',
           {'same_as_keel_inactive': base == on, 'answers_with_keel_on': on, 'docs_name_woocommerce': 'WooCommerce' in open(KEEL + '/readme.txt').read()})


def phase_multisite():
    site = 'ms'
    s2 = 'http://ms.test/site2/'
    keel(site, False)
    wp(site, 'plugin', 'activate', 'keel-defaults', '--network')
    flag(site, 'mail-fail', False)

    # P27: no network policy, differing local choices.
    network(site, 'unset')
    setting(site, 'yes')
    setting(site, 'no', url=s2)
    main = capture(site)
    sub = capture(site, prefix='/site2')
    m_ok, _ = uniform_login(main)
    s_ok, s_errs = uniform_login(sub)
    record('P27', 'ms', 'PASS' if m_ok and not s_ok else 'FAIL', {'main_on_uniform': m_ok, 'site2_off_uniform': s_ok, 'site2_errors': [e[:50] for e in s_errs]})

    # P28: whoever serves the lost-password form decides, and the docs say which site that is.
    def link_on(host, path):
        page = req(site, 'GET', path, host=host)['body']
        m = re.search(r'href="([^"]*action=lostpassword[^"]*)"', page)
        return urllib.parse.urlsplit(html.unescape(m.group(1))) if m else None

    def lost_at(link):
        # POST to the link the login page actually renders, on the host it names.
        out = {'lost': {}}
        for key, ul in LOST_INPUTS:
            r = lostpw(site, ul, path=f'{link.path}?{link.query}', host=link.hostname)
            out['lost'][key] = {'status': r['status'], 'location': r['location'], 'error': login_error(r['body']),
                                'mails': sum(x['recipients'] for x in logged(site, 'mail.log', r['rid']))}
        return uniform_lost(out)[0]

    p28 = {}
    l2 = link_on('ms.test', '/site2/wp-login.php')
    p28['site2_link'] = l2.geturl() if l2 else None
    p28['site2_serves_own_form'] = bool(l2) and l2.hostname == 'ms.test' and l2.path == '/site2/wp-login.php'
    # Direction A: main on, site2 off (set above for P27).
    p28['A_site2_login_uniform'] = s_ok
    p28['A_site2_lost_uniform'] = lost_at(l2)
    # Direction B: main off, site2 on.
    setting(site, 'no')
    setting(site, 'yes', url=s2)
    p28['B_site2_login_uniform'] = uniform_login(capture(site, prefix='/site2'))[0]
    p28['B_site2_lost_uniform'] = lost_at(l2)
    # Mapped domain, site3 on, main off: its link goes to the main site's form, which the main site decides.
    setting(site, 'yes', url='http://mapped.test/')
    l3 = link_on('mapped.test', '/wp-login.php')
    p28['site3_link'] = l3.geturl() if l3 else None
    p28['site3_links_to_main'] = bool(l3) and l3.hostname == 'ms.test' and l3.path == '/wp-login.php'
    p28['site3_login_uniform'] = uniform_login(capture(site, host='mapped.test'))[0]
    p28['site3_lost_follows_main_off'] = not lost_at(l3)
    setting(site, 'yes')
    p28['site3_lost_follows_main_on'] = lost_at(l3)
    detail = wp(site, 'eval', 'echo keel_defaults_strings()["uniform_login_answers"]["detail"];')
    p28['docs_say_no_path_goes_to_main'] = 'no path of its own' in detail and 'no path of its own' in open(KEEL + '/readme.txt').read() \
        and 'no path of its own' in open(KEEL + '/docs/wordpress-default-settings.md').read()
    ok28 = p28['site2_serves_own_form'] and not p28['A_site2_login_uniform'] and not p28['A_site2_lost_uniform'] \
        and p28['B_site2_login_uniform'] and p28['B_site2_lost_uniform'] and p28['site3_links_to_main'] and p28['site3_login_uniform'] \
        and p28['site3_lost_follows_main_off'] and p28['site3_lost_follows_main_on'] and p28['docs_say_no_path_goes_to_main']
    record('P28', 'ms', 'PASS' if ok28 else 'FAIL', p28)
    setting(site, 'no', url=s2)

    # P26: network policy on beats site2's own "no".
    print(network(site, 'yes'))
    sub = capture(site, prefix='/site2')
    s_ok, _ = uniform_login(sub)
    l_ok, _ = uniform_lost(sub)
    ck = admin_cookies(site, 'siteadmin', path_prefix='/site2')
    page = req(site, 'GET', '/site2/wp-admin/options-general.php?page=keel', cookies=ck)['body']
    ms_ = re.findall(r'<input type="checkbox"[^>]*name="keel_settings\[uniform_login_answers\]"[^>]*>', page)
    locked = bool(ms_ and 'disabled' in ms_[0])
    i = page.find('keel-uniform_login_answers-desc')
    note = re.sub('<[^>]+>', ' ', page[i:i + 1500])
    note = re.sub(r'\s+', ' ', note)[:300]
    record('P26', 'ms', 'PASS' if s_ok and l_ok and locked else 'FAIL', {'site2_login_uniform': s_ok, 'site2_lost_uniform': l_ok, 'checkbox': ms_[0] if ms_ else None, 'near_text': note})

    # P29: network-only, spammed, pending signup on site2 vs unknown.
    ref = shaped(login(site, 'nobody', 'x-wrong', path='/site2/wp-login.php'), 'nobody')
    p29 = {}
    for who in ('netonly', 'spammy', 'pendingone'):
        p29[who] = same(shaped(login(site, who, 'x-wrong', path='/site2/wp-login.php'), who), ref)
    record('P29', 'ms', 'PASS' if all(p29.values()) else 'FAIL', p29)

    # P30 multisite: a site admin can't set network policy; a Super Admin can, through the same request.
    # The network form as it posts with Login Answers managed and unticked.
    payload = {'keel_network_manage[uniform_login_answers]': '1'}
    getnet = lambda: wp(site, 'site', 'option', 'get', 'keel_network_settings', '--format=json', check=False)
    network(site, 'unset')
    before = getnet()
    ev = {}
    r = req(site, 'POST', '/site2/wp-admin/network/settings.php?page=keel-network',
            dict(payload, keel_network_nonce=lab_nonce(site, 'keel-network-save', ck, prefix='/site2')), cookies=ck)
    ev['site_admin_status'] = r['status']
    ev['site_admin_refused_by'] = denial(r, 'network')
    if ev['site_admin_refused_by'] == 'other':
        ev['site_admin_body'] = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', r['body'][r['body'].find('<body'):]))[:300]
    ev['network_option_unchanged'] = getnet() == before
    ck_s = admin_cookies(site)
    r = req(site, 'POST', '/wp-admin/network/settings.php?page=keel-network',
            dict(payload, keel_network_nonce=lab_nonce(site, 'keel-network-save', ck_s)), cookies=ck_s)
    ev['super_admin_status'] = r['status']
    pol = wp(site, 'eval', 'echo wp_json_encode(keel_defaults_network_settings());')
    ev['super_admin_policy'] = pol
    ev['super_admin_set_it'] = r['status'] == 302 and '"uniform_login_answers":"no"' in pol
    ok = ev['site_admin_status'] == 403 and ev['site_admin_refused_by'] == 'capability' and ev['network_option_unchanged'] and ev['super_admin_set_it']
    record('P30', 'ms', 'PASS' if ok else 'FAIL', ev)
    network(site, 'unset')
    setting(site, 'yes', url=s2)


if __name__ == '__main__':
    phase = sys.argv[1]
    arg = sys.argv[2] if len(sys.argv) > 2 else None
    try:
        {'single': phase_single, 'fresh': phase_fresh, 'upgrade': phase_upgrade, 'editor': phase_editor,
         'timing': phase_timing, 'woo': phase_woo}.get(phase, lambda a: phase_multisite())(arg)
    finally:
        json.dump(R, open(f'{LAB}/results/{phase}-{arg or "ms"}.json', 'w'), indent=1)
    failed = sorted(f'{pid} [{cell}]' for pid, cells in R.items() for cell, v in cells.items() if v['result'] == 'FAIL')
    if failed:
        print('FAILED: ' + ', '.join(failed), file=sys.stderr)
        sys.exit(1)
