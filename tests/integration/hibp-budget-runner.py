#!/usr/bin/env python3
"""Independent PHP/real-WordPress HIBP lab controller (no site provisioning).

Invoke through Studio for local labs, or from a disposable CI job:
  KEEL_HIBP_LAB=1 python3 hibp-budget-runner.py --wp /lab/wp --output /tmp/report.json
Requires the site's .hibp-budget-lab marker and worker's WP config authorization.
--php selects Studio's native PHP binary. Never sets GITHUB_ACTIONS to bypass guards.
Only the sanitized report is retained; job files/tickets/logs are temporary.
"""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time


class LabFailure(Exception):
    """A harness assertion, never SQL, HTTP payloads or configuration."""


def require(condition, message):
    if not condition:
        raise LabFailure(message)


class Worker:
    def __init__(self, lab, op, **arguments):
        self.directory = lab.root / str(lab.counter)
        lab.counter += 1
        self.directory.mkdir()
        self.started = time.monotonic()
        job = dict(arguments, wp=str(lab.wp), work=str(self.directory), op=op)
        if lab.url is not None:
            job['url'] = lab.url
        path = self.directory / 'job.json'
        path.write_text(json.dumps(job))
        self.log = (self.directory / 'worker.log').open('wb')
        self.process = subprocess.Popen(
            [lab.php, str(lab.worker), str(path)], stdout=self.log,
            stderr=subprocess.STDOUT, env=os.environ.copy(), start_new_session=True,
        )
        lab.children.append(self)

    def wait_file(self, name, timeout=20):
        path = self.directory / name
        deadline = time.monotonic() + timeout
        while not path.is_file():
            if self.process.poll() is not None:
                raise LabFailure('worker exited before checkpoint ' + name)
            if time.monotonic() >= deadline:
                raise LabFailure('worker checkpoint timed out: ' + name)
            time.sleep(0.005)
        return json.loads(path.read_text()) if name.endswith('.json') else None

    def release(self, name='resume'):
        (self.directory / name).touch()

    def result(self, timeout=35):
        try:
            code = self.process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            self.kill()
            raise LabFailure('worker exceeded operation timeout') from None
        self.log.close()
        path = self.directory / 'result.json'
        require(path.is_file(), 'worker produced no structured result (logs withheld)')
        data = json.loads(path.read_text())
        # Do not propagate raw exception strings or PHP stderr into the report.
        require(code == 0 and data.get('ok') is True,
                'worker operation failed: ' + str(data.get('error', 'no sanitized assertion'))[:250])
        return data

    def kill(self):
        if self.process.poll() is None:
            os.killpg(self.process.pid, signal.SIGKILL)
            self.process.wait(timeout=10)
        self.log.close()


class Lab:
    def __init__(self, args, root):
        self.wp = Path(args.wp).resolve()
        self.url = args.url
        self.php = args.php
        self.worker = Path(__file__).with_name('hibp-budget-worker.php').resolve()
        self.root = Path(root)
        self.counter = 0
        self.children = []
        self.report = {'schema': 1, 'transport': 'synthetic WordPress pre_http_request; no live HIBP calls',
                       'cases': [], 'performance': {}, 'limitations': [
                           'Lease caps bound valid admissions, not physical sockets after suspension.',
                           'Expired-owner tests advance persisted lease expiry via fixture CAS; no wall-clock override.',
                           'Multisite and persistent-cache proof require the corresponding provisioned lab.',
                       ]}

    def spawn(self, op, **arguments):
        return Worker(self, op, **arguments)

    def call(self, op, timeout=35, **arguments):
        return self.spawn(op, **arguments).result(timeout)

    def reset(self, credit=None):
        self.call('reset')
        if credit is not None:
            self.call('seed', credit=credit)

    def probe(self):
        return self.call('probe')['result']

    def race(self, count, privileged=False):
        gate = self.root / ('barrier-' + str(self.counter))
        workers = [self.spawn('admit', privileged=privileged, barrier=str(gate)) for _ in range(count)]
        for worker in workers:
            worker.wait_file('ready')
        gate.touch()
        return [worker.result() for worker in workers]

    @staticmethod
    def tickets(results):
        return [data['result']['ticket'] for data in results if 'ticket' in data['result']]

    def finish(self, ticket, live=False):
        data = self.call('finish', ticket=ticket, live=live)
        require(data['result']['finish'] is True, 'owned completion failed')

    @staticmethod
    def only_the_allowance(probe):
        return [row['key'] for row in probe['rows']] == ['state'] and probe['rows'][0]['bytes'] <= 4096

    def record_performance(self, name, data, operations=1, unit='operation'):
        self.report['performance'][name] = {
            'milliseconds': data['milliseconds'], 'peak_bytes': data['peak_bytes'],
            'metrics': data['metrics'], 'bootstrap': data['bootstrap'],
            'operations': operations, 'operation_unit': unit,
            'per_operation': {
                'milliseconds': round(data['milliseconds'] / operations, 6),
                **{key: round(value / operations, 6) for key, value in data['metrics'].items()},
            },
            'timing_scope': 'operation only; excludes WordPress bootstrap and process startup',
            'memory_scope': 'whole-worker peak, not incremental operation allocation',
        }

    def adapter(self):
        self.reset()
        data = self.call('adapter')
        self.report['backend'] = {k: data['result'][k] for k in ('class', 'engine')}
        self.record_performance('adapter', data)

    def initialization(self):
        self.reset()
        # Privileged throughout: these cases measure the whole allowance. The
        # part kept back from everyone else has its own case, reserve.
        results = self.race(16, privileged=True)
        tickets = self.tickets(results)
        require(1 <= len(tickets) <= 2, 'initialization race exceeded lease cap or made no progress')
        state = self.probe()['state']
        require(len(state['leases']) <= 2, 'initialization persisted too many leases')
        require(state['credit'] <= (10 - len(tickets)) * 60, 'initialization minted credit')
        for ticket in tickets:
            self.finish(ticket)
        admitted = len(tickets)
        # Drain in fresh processes; failures never refund the initial burst.
        for i in range(12):
            data = self.call('admit', privileged=True)['result']
            if 'ticket' not in data:
                require(data.get('error') == 'budget_exhausted', 'drain failed for a reason other than exhaustion')
                break
            admitted += 1
            self.finish(data['ticket'])
        require(admitted <= 10, 'initialization race created extra burst allowance')
        require(self.probe()['state']['credit'] < 60, 'initialization drain did not exhaust allowance')

    def exact_burst(self):
        self.reset(600)
        initial = self.probe()['state']
        require(initial['policy'] == {'capacity': 10, 'interval': 60},
                'exact burst requires the approved default policy')
        cap = 600
        cost = 60
        credited = 0
        attempts = 0
        for i in range(10):
            before = self.probe()['state']
            started = time.monotonic()
            admission = self.call('admit', privileged=True)
            attempts += 1
            require('ticket' in admission['result'], 'uncontended initial burst lost a credit/admission')
            require(admission['metrics']['http'] == 0, 'admission-only burst reached HTTP')
            after = self.probe()['state']
            elapsed = after['last'] - before['last']
            require(0 <= elapsed <= math.ceil(time.monotonic() - started) + 2,
                    'admission refill elapsed beyond the measured wall-clock bound')
            refilled = min(cap, before['credit'] + elapsed)
            require(after['credit'] == refilled - cost,
                    'uncontended admission did not conserve one-second credit units')
            credited += refilled - before['credit']
            self.finish(admission['result']['ticket'])
            released = self.probe()['state']
            require(released['credit'] == after['credit'] and released['last'] == after['last'],
                    'completion refunded credit or persisted an unearned refill')
            require(not released['leases'], 'uncontended completion left a live lease')
        final = self.probe()['state']
        require(final['last'] - initial['last'] < cost,
                'burst lab exceeded one refill interval; eleventh-denial precondition not met')
        require(final['credit'] == initial['credit'] + credited - attempts * cost,
                'aggregate uncontended burst did not conserve credit')
        denied = self.call('admit', privileged=True)
        attempts += 1
        require(denied['result'].get('error') == 'budget_exhausted',
                'eleventh uncontended request was not denied')
        require(denied['metrics']['http'] == 0, 'exhausted admission reached HTTP')
        after_denial = self.probe()['state']
        require(after_denial['credit'] == final['credit'] and after_denial['last'] == final['last'],
                'denied admission mutated authority/refilled persistently')
        self.report['burst_conservation'] = {
            'attempts': attempts, 'admitted': 10, 'denied': 1,
            'initial_credit_units': initial['credit'], 'refilled_credit_units': credited,
            'spent_credit_units': 10 * cost, 'remaining_credit_units': final['credit'],
        }

    def last_credit(self):
        self.reset(60)
        results = self.race(8, privileged=True)
        require(len(self.tickets(results)) == 1, 'last credit admitted other than one worker')
        require(self.probe()['state']['credit'] < 60, 'last credit was refunded or duplicated')

    def cap_two(self):
        self.reset(600)
        results = self.race(12)
        require(1 <= len(self.tickets(results)) <= 2, 'two-lease cap failed')
        require(len(self.probe()['state']['leases']) <= 2, 'two-lease persisted cap failed')

    def lanes(self):
        # Two lookups may be in flight, one per lane. A lane is taken because it
        # is free, never because of what is being looked up.
        self.reset(600)
        tickets = self.tickets(self.race(8))
        require(1 <= len(tickets) <= 2, 'lane race exceeded the two lanes or made no progress')
        taken = sorted(ticket['slot'] for ticket in tickets)
        require(taken == list(range(len(taken))), 'racing owners did not take distinct lanes from the lowest')
        probe = self.probe()
        require(sorted(lease['slot'] for lease in probe['state']['leases']) == taken,
                'persisted leases disagree with the tickets handed out')
        require(self.only_the_allowance(probe), 'lookups in flight created rows beside the allowance')
        for ticket in tickets:
            self.finish(ticket)
        self.reset(600)
        first = self.call('admit')['result']
        second = self.call('admit')['result']
        require('ticket' in first and 'ticket' in second, 'two uncontended lookups were not both admitted')
        require((first['ticket']['slot'], second['ticket']['slot']) == (0, 1), 'lanes were not taken in order')
        require(set(first['ticket']) == {'slot', 'owner', 'until', 'scope'},
                'a ticket carries more than a lane, an owner, an expiry and the scope')
        require(self.call('admit')['result'].get('error') == 'lookup_busy', 'a third lookup was admitted')
        require(self.call('admit', privileged=True)['result'].get('error') == 'lookup_busy',
                'a third lookup was admitted for a privileged account')
        self.finish(first['ticket'])
        third = self.call('admit')['result']
        require('ticket' in third and third['ticket']['slot'] == 0, 'the freed lane was not the next one taken')
        self.finish(third['ticket'])
        self.finish(second['ticket'])
        require(not self.probe()['state']['leases'], 'completed lookups left leases behind')

    def reserve(self):
        # Half the allowance is kept back for privileged accounts. Everyone else
        # stops at the line, however many of them ask and however fast.
        self.reset(600)
        policy = self.probe()['state']['policy']
        require(policy == {'capacity': 10, 'interval': 60}, 'reserve case requires the approved default policy')
        line = 300
        admitted = 0
        for _ in range(8):
            data = self.call('admit')['result']
            if 'ticket' not in data:
                require(data.get('error') == 'budget_exhausted', 'ordinary drain stopped for a reason other than the line')
                break
            admitted += 1
            self.finish(data['ticket'])
        require(admitted == 5, 'ordinary requests did not stop at half the allowance')
        at_line = self.probe()
        require(line <= at_line['state']['credit'] < line + 60, 'ordinary drain did not leave the reserve intact')
        results = self.race(8)
        require(not self.tickets(results), 'racing ordinary requests got into the reserve')
        require(all(data['result'].get('error') == 'budget_exhausted' for data in results),
                'ordinary requests at the line were refused for another reason')
        require(all(data['metrics']['store_writes'] == 0 and data['metrics']['http'] == 0 for data in results),
                'a refusal at the line wrote to the store or reached HTTP')
        require(self.probe()['rows'] == at_line['rows'], 'refusals at the line changed the allowance')
        # The same moment, for a privileged account.
        data = self.call('admit', privileged=True)['result']
        require('ticket' in data, 'a privileged request was refused with the reserve intact')
        self.finish(data['ticket'], live=True)
        below = self.probe()['state']
        require(line - 60 <= below['credit'] < line, 'the privileged request was not charged to the reserve')
        require(self.call('admit')['result'].get('error') == 'budget_exhausted',
                'an ordinary request was admitted below the line')
        # End to end: the same lookup, unknown for one and answered for the other.
        ordinary = self.call('lookup', password='synthetic-reserve-fixture')
        require(ordinary['result']['verdicts'] == [None] and ordinary['metrics']['http'] == 0,
                'an ordinary lookup below the line was answered or reached HTTP')
        privileged = self.call('lookup', password='synthetic-reserve-fixture', privileged=True)
        require(privileged['result']['verdicts'] == [True] and privileged['metrics']['http'] == 1,
                'a privileged lookup below the line was not answered from one request')
        # One credit above the line admits exactly one of many ordinary requests.
        self.reset(line + 60)
        require(len(self.tickets(self.race(8))) == 1, 'one credit above the line admitted other than one ordinary request')
        require(line <= self.probe()['state']['credit'] < line + 60, 'the race above the line reached into the reserve')
        # And the reserve itself still honours the last credit and the two lanes.
        self.reset(line)
        tickets = self.tickets(self.race(8, privileged=True))
        require(1 <= len(tickets) <= 2, 'privileged race exceeded the two lanes or made no progress')
        self.reset(60)
        require(len(self.tickets(self.race(8, privileged=True))) == 1, 'the last credit of the reserve admitted other than one')
        self.report['reserve'] = {'capacity': 10, 'kept_back': 5, 'ordinary_admitted_before_line': admitted}

    def stale(self, phase):
        self.reset(600)
        password = 'synthetic-stale-owner-fixture'
        old = self.spawn('lookup', password=password, pause=phase)
        old.wait_file('paused.json')
        require(bool(self.probe()['state']['leases']), 'checkpoint did not follow a committed reservation')
        self.call('expire')
        replacement = self.call('admit')['result']
        require('ticket' in replacement, 'replacement owner failed to acquire the expired lane')
        before = self.probe()
        old.release()
        data = old.result()
        require(data['result']['verdicts'] == [None], 'stale completion was presented as a verdict')
        if phase == 'after_http':
            require(data['metrics']['http'] == 1, 'stale HTTP fixture did not exercise transport')
        else:
            require(data['metrics']['http'] == 0, 'stale reservation reached HTTP')
        after = self.probe()
        require(self.only_the_allowance(after), 'stale owner left a row beside the allowance')
        require(after['state']['leases'] == before['state']['leases'],
                'old worker released or replaced the replacement lease')
        require(after['state']['credit'] == before['state']['credit'], 'stale owner restored or spent credit')
        require(after['state']['success'] == before['state']['success'],
                'stale completion fabricated live success evidence')
        sample = after['state']['sample']
        require(isinstance(sample, dict) and sample.get('kind') == 'budget_unavailable',
                'stale completion failed to sample budget_unavailable')
        require(isinstance(sample.get('first'), int) and isinstance(sample.get('seen'), int)
                and sample['first'] <= sample['seen'] <= int(time.time()) + 1,
                'stale unavailability sample has invalid timestamps')
        if phase == 'after_http':
            passive = self.call('evidence')
            evidence = passive['result']['evidence']
            require(evidence['success'] == before['state']['success']
                    and evidence['failure'] == sample,
                    'passive diagnostics lost the stale HTTP unknown/sample contract')
            require(passive['metrics']['store_writes'] == 0 and passive['metrics']['http'] == 0,
                    'reading stale HTTP evidence mutated authority or reached HTTP')
            self.report['stale_http_diagnostic'] = {
                'success_unchanged': True, 'sampled_reason': 'budget_unavailable',
                'verdict': 'unknown',
            }
        self.finish(replacement['ticket'])

    def stale_finish(self):
        self.reset(600)
        old = self.call('admit')['result']
        require('ticket' in old, 'stale-completion fixture admission failed')
        self.call('expire')
        # Past its expiry a lease is void, whether or not anyone has taken its place yet.
        unreplaced = self.probe()
        require(len(unreplaced['state']['leases']) == 1, 'expired lease fixture was reaped early')
        data = self.call('finish', ticket=old['ticket'], live=True)
        require(data['result']['finish'] is False, 'an expired lease completed before anyone replaced it')
        require(unreplaced['rows'] == self.probe()['rows'], 'an expired lease altered the allowance')
        newer = self.call('admit')['result']
        require('ticket' in newer, 'stale-completion replacement failed')
        require(newer['ticket']['slot'] == old['ticket']['slot'] and newer['ticket']['owner'] != old['ticket']['owner'],
                'replacement did not take over the expired lane as a new owner')
        before = self.probe()
        for live in (True, False):
            data = self.call('finish', ticket=old['ticket'], live=live)
            require(data['result']['finish'] is False, 'an expired ticket completed over its replacement')
        require(before['rows'] == self.probe()['rows'], 'an expired ticket altered the allowance')
        self.finish(newer['ticket'])

    def killed_reservation(self, phase):
        self.reset(600)
        password = 'synthetic-killed-owner-fixture'
        worker = self.spawn('lookup', password=password, pause=phase)
        worker.wait_file('paused.json')
        before = self.probe()['state']
        require(bool(before['leases']) and before['credit'] < 600, 'kill checkpoint did not spend allowance')
        worker.kill()
        require(self.probe()['state']['credit'] == before['credit'], 'worker death refunded credit')
        self.call('expire')
        replacement = self.call('admit')['result']
        require('ticket' in replacement, 'expired killed lease did not recover')
        require(self.probe()['state']['credit'] < before['credit'], 'replacement did not spend another credit')
        self.finish(replacement['ticket'])

    def finish_rollback(self):
        self.reset(600)
        admission = self.call('admit')['result']
        require('ticket' in admission, 'completion fixture admission failed')
        before = self.probe()
        worker = self.spawn('finish', ticket=admission['ticket'], live=True, pause='finish_locked')
        worker.wait_file('paused.json')
        # A separate connection must not observe the uncommitted control CAS.
        during = self.probe()
        require(before['rows'] == during['rows'], 'uncommitted completion became visible to another worker')
        worker.kill()
        require(before['rows'] == self.probe()['rows'], 'SIGKILL did not roll back the completion transaction')
        self.finish(admission['ticket'], live=True)
        after = self.probe()['state']
        require(not after['leases'] and isinstance(after['success'], int), 'the owner could not complete after the rollback')

    def connection_loss(self):
        self.reset(600)
        admission = self.call('admit')['result']
        require('ticket' in admission, 'connection-loss fixture admission failed')
        worker = self.spawn('finish', ticket=admission['ticket'], live=True, pause='finish_locked')
        checkpoint = worker.wait_file('paused.json')
        require(isinstance(checkpoint.get('connection'), int), 'native connection checkpoint missing')
        self.call('kill_connection', connection=checkpoint['connection'])
        self.call('expire')
        newer = self.call('admit')['result']
        require('ticket' in newer, 'replacement failed after connection loss')
        before = self.probe()
        worker.release()
        old = worker.result()
        require(old['result']['finish'] is False, 'lost transaction reconnected and completed')
        after = self.probe()
        require(self.only_the_allowance(after), 'reconnected old owner left a row beside the allowance')
        require(before['state']['leases'] == after['state']['leases'], 'reconnected old owner released replacement lease')
        require(before['state']['credit'] == after['state']['credit'], 'reconnected old owner restored spent credit')
        require(before['state']['success'] == after['state']['success'], 'connection loss recorded false success')
        self.finish(newer['ticket'])

    def no_retention(self):
        # A reply answers the request that asked for it and is gone. What is
        # left is one row of counters, whatever was looked up.
        self.reset(600)
        password = 'synthetic-no-retention-fixture'
        digest = hashlib.sha1(password.encode()).hexdigest()
        first = self.call('lookup', password=password)
        require(first['result']['verdicts'] == [True] and first['metrics']['http'] == 1,
                'first lookup did not validate the synthetic breach response')
        self.record_performance('lookup', first, unit='lookup')
        probe = self.probe()
        require(self.only_the_allowance(probe), 'a lookup left a row beside the allowance')
        state = probe['state']
        require(list(state) == ['v', 'rev', 'policy', 'credit', 'last', 'leases', 'sample', 'success']
                and state['leases'] == [], 'the allowance row holds more than counters at rest')
        stored = json.dumps(state).lower()
        require(digest[:5] not in stored and digest[5:] not in stored and password not in stored,
                'the allowance row holds something of the value that was checked')
        spent = state['credit']
        # A later request for the same value asks again and pays again.
        again = self.call('lookup', password=password)
        require(again['result']['verdicts'] == [True] and again['metrics']['http'] == 1,
                'a later request was answered without a lookup: something was kept')
        require(self.probe()['state']['credit'] < spent, 'a later request did not spend allowance')
        # Within one request the same value is asked about once.
        self.reset(600)
        repeat = self.call('lookup', password=password, count=100)
        require(all(value is True for value in repeat['result']['verdicts']), 'a repeat within the request lost its answer')
        require(repeat['metrics']['http'] == 1, 'repeats within one request went out again')
        require(540 <= self.probe()['state']['credit'] < 600, 'repeats within one request spent more than one credit')
        self.record_performance('repeat_in_request_100', repeat, operations=100, unit='lookup')
        # Told apart as separate requests, each one goes out, until the line.
        self.reset(600)
        separate = self.call('lookup', password=password, count=8, requests=True)
        require(separate['result']['verdicts'] == [True] * 5 + [None] * 3 and separate['metrics']['http'] == 5,
                'separate requests were not each looked up, or passed the reserve')
        # With the allowance spent there is nothing to fall back on.
        self.call('seed', credit=0)
        before = self.probe()
        self.call('flush')
        after = self.probe()
        require(before['rows'] == after['rows'], 'cache eviction altered authoritative rows')
        spent_out = self.call('lookup', password=password, privileged=True)
        require(spent_out['result']['verdicts'] == [None] and spent_out['metrics']['http'] == 0,
                'a spent allowance still answered: something was kept')
        denied = self.call('lookup', password='synthetic-unrelated-denial-fixture', count=100, alternate=True)
        require(all(value is None for value in denied['result']['verdicts']), 'denial became a clean verdict')
        require(denied['metrics']['http'] == 0 and denied['metrics']['store_writes'] <= 1, 'denials caused HTTP or per-attempt evidence writes')
        self.record_performance('exhausted_100', denied, operations=100, unit='lookup')
        self.report['persistent_cache'] = before['persistent_cache']
        passive = self.call('evidence')
        require(passive['metrics']['http'] == 0 and passive['metrics']['store_writes'] == 0, 'passive evidence mutated store or called HTTP')
        noop = self.call('noop')
        require(noop['bootstrap']['store_reads'] == 0 and noop['bootstrap']['store_writes'] == 0
                and noop['bootstrap']['http'] == 0, 'ordinary bootstrap touched HIBP authority/transport')
        self.record_performance('no_screening_bootstrap', noop)
        require(self.only_the_allowance(self.probe()), 'the case ended with a row beside the allowance')

    def storage(self, count):
        self.reset()
        data = self.call('storage', count=count, timeout=240)
        rows = data['result']['rows']
        require(len(rows) == 1 and rows[0]['key'] == 'state', 'storage exercise left more than the control row')
        require(rows[0]['bytes'] <= 4096, 'encoded bound exceeded')
        self.record_performance('storage_' + str(count), data, operations=count, unit='storage_iteration')

    def legacy_rows(self):
        # Rows left by a version that kept replies are deleted, by fixed name.
        self.reset()
        data = self.call('legacy')
        rows = data['result']['rows']
        require(len(rows) == 1 and rows[0]['key'] == 'state', 'kept replies survived the clean-up')
        require(data['metrics']['http'] == 0, 'clean-up reached HTTP')

    def scope(self):
        self.reset(0)
        data = self.call('scope')
        self.report['multisite'] = data['result']['multisite']

    def uninstall(self):
        require(os.environ.get('GITHUB_ACTIONS') == 'true',
                'full uninstall case is prohibited outside disposable CI')
        data = self.call('uninstall')
        require(data['result'].get('fixed_rows_removed') is True,
                'CI uninstall did not confirm fixed rows removed in all networks')
        require(data['metrics']['http'] == 0, 'uninstall proof reached HTTP')
        self.report['uninstall'] = 'CI-only full uninstall: stale option-miss cache and all-network fixed rows checked'

    def close(self):
        for child in self.children:
            child.kill()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp', required=True)
    parser.add_argument('--output', required=True, help='Sanitized JSON report path')
    parser.add_argument('--url', help='WordPress bootstrap URL forwarded to every worker job')
    parser.add_argument('--php', default=os.environ.get('KEEL_HIBP_PHP', shutil.which('php')))
    parser.add_argument('--storage-count', type=int, default=2048)
    parser.add_argument('--cases', default='all', help='Comma-separated case names or all')
    args = parser.parse_args()
    if os.environ.get('GITHUB_ACTIONS') != 'true' and os.environ.get('KEEL_HIBP_LAB') != '1':
        parser.error('explicit KEEL_HIBP_LAB=1 or GitHub Actions required; no site is provisioned')
    if not args.php or not (Path(args.wp) / '.hibp-budget-lab').is_file() or not (Path(args.wp) / 'wp-load.php').is_file():
        parser.error('native PHP, wp-load.php and explicit .hibp-budget-lab marker required')
    if not 64 <= args.storage_count <= 8192:
        parser.error('--storage-count must be between 64 and 8192')
    with tempfile.TemporaryDirectory(prefix='hibp-budget-workers-') as root:
        lab = Lab(args, root)
        cases = {
            'adapter': lab.adapter,
            'initialization': lab.initialization,
            'exact_burst': lab.exact_burst,
            'last_credit': lab.last_credit,
            'cap_two': lab.cap_two,
            'lanes': lab.lanes,
            'reserve': lab.reserve,
            'stale_reservation': lambda: lab.stale('after_reservation'),
            'stale_http': lambda: lab.stale('after_http'),
            'stale_finish': lab.stale_finish,
            'kill_reservation': lambda: lab.killed_reservation('after_reservation'),
            'kill_http': lambda: lab.killed_reservation('after_http'),
            'finish_rollback': lab.finish_rollback,
            'no_retention': lab.no_retention,
            'storage': lambda: lab.storage(args.storage_count),
            'legacy_rows': lab.legacy_rows,
            'scope': lab.scope,
        }
        if os.environ.get('GITHUB_ACTIONS') == 'true':
            cases['connection_loss'] = lab.connection_loss
            cases['uninstall'] = lab.uninstall
        selected = list(cases) if args.cases == 'all' else args.cases.split(',')
        # Destructive full uninstall is CI-only and always follows all other cases.
        if 'uninstall' in selected:
            selected = [name for name in selected if name != 'uninstall'] + ['uninstall']
        if any(name not in cases for name in selected):
            parser.error('unknown case name')
        failed = False
        try:
            for name in selected:
                started = time.monotonic()
                try:
                    cases[name]()
                    outcome = {'name': name, 'status': 'passed'}
                except (LabFailure, OSError, ValueError) as error:
                    outcome = {'name': name, 'status': 'failed',
                               'reason': str(error) if isinstance(error, LabFailure) else 'harness I/O or JSON failure'}
                    failed = True
                outcome['seconds'] = round(time.monotonic() - started, 3)
                lab.report['cases'].append(outcome)
                print(name + ': ' + outcome['status'], flush=True)
                # Do not let a paused/failed worker hold a lock into the next case.
                lab.close()
                output = Path(args.output)
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_text(json.dumps(lab.report, indent=2) + '\n')
        finally:
            lab.close()
            # Final cleanup itself uses fixed keys only. The separate full uninstall
            # case above is exposed exclusively to disposable GitHub Actions jobs.
            try:
                lab.call('reset')
                require(lab.probe()['rows'] == [], 'final fixed-key cleanup left rows behind')
                lab.report['cleanup'] = 'canonical scope fixed budget rows removed; no uninstall'
            except (LabFailure, OSError, ValueError):
                lab.report['cleanup'] = 'failed: fixed budget rows require owner inspection'
                failed = True
            finally:
                lab.close()
                output = Path(args.output)
                output.parent.mkdir(parents=True, exist_ok=True)
                output.write_text(json.dumps(lab.report, indent=2) + '\n')
        return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
