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
                'worker operation failed; inspect its assertion locally before temporary cleanup')
        return data

    def kill(self):
        if self.process.poll() is None:
            os.killpg(self.process.pid, signal.SIGKILL)
            self.process.wait(timeout=10)
        self.log.close()


class Lab:
    def __init__(self, args, root):
        self.wp = Path(args.wp).resolve()
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

    def race(self, prefixes):
        gate = self.root / ('barrier-' + str(self.counter))
        workers = [self.spawn('admit', prefix=p, barrier=str(gate)) for p in prefixes]
        for worker in workers:
            worker.wait_file('ready')
        gate.touch()
        return [worker.result() for worker in workers]

    @staticmethod
    def tickets(results):
        return [data['result']['ticket'] for data in results if 'ticket' in data['result']]

    def finish(self, ticket, body=None):
        data = self.call('finish', ticket=ticket, body=body)
        require(data['result']['finish'] is True, 'owned completion failed')

    def record_performance(self, name, data):
        self.report['performance'][name] = {
            'milliseconds': data['milliseconds'], 'peak_bytes': data['peak_bytes'],
            'metrics': data['metrics'], 'bootstrap': data['bootstrap'],
        }

    def adapter(self):
        self.reset()
        data = self.call('adapter')
        self.report['backend'] = {k: data['result'][k] for k in ('class', 'engine')}
        self.record_performance('adapter', data)

    def initialization(self):
        self.reset()
        results = self.race([f'{i:02X}000' for i in range(16)])
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
            data = self.call('admit', prefix=f'{i + 16:02X}001')['result']
            if 'ticket' not in data:
                require(data.get('error') == 'budget_exhausted', 'drain failed for a reason other than exhaustion')
                break
            admitted += 1
            self.finish(data['ticket'])
        require(admitted <= 10, 'initialization race created extra burst allowance')
        require(self.probe()['state']['credit'] < 60, 'initialization drain did not exhaust allowance')

    def last_credit(self):
        self.reset(60)
        results = self.race([f'{i:02X}020' for i in range(8)])
        require(len(self.tickets(results)) == 1, 'last credit admitted other than one worker')
        require(self.probe()['state']['credit'] < 60, 'last credit was refunded or duplicated')

    def cap_two(self):
        self.reset(600)
        results = self.race([f'{i:02X}030' for i in range(12)])
        require(1 <= len(self.tickets(results)) <= 2, 'two-lease cap failed')
        require(len(self.probe()['state']['leases']) <= 2, 'two-lease persisted cap failed')

    def same_slot(self):
        self.reset(600)
        # First byte modulo 64: different prefixes, same fixed slot.
        results = self.race(['00001', '40001', '80001', 'C0001'] * 2)
        require(len(self.tickets(results)) == 1, 'same-slot race admitted more than one owner')

    @staticmethod
    def slot_fingerprints(probe):
        return [(row['key'], row['hash']) for row in probe['rows'] if row['key'].startswith('slot_')]

    def stale(self, phase):
        self.reset(600)
        password = 'synthetic-stale-owner-fixture'
        prefix = hashlib.sha1(password.encode()).hexdigest()[:5].upper()
        op = 'lookup' if phase == 'after_http' else 'admit'
        old = self.spawn(op, prefix=prefix, password=password, pause=phase)
        old.wait_file('paused.json')
        require(bool(self.probe()['state']['leases']), 'checkpoint did not follow a committed reservation')
        self.call('expire')
        replacement = self.call('admit', prefix=prefix)['result']
        require('ticket' in replacement, 'replacement owner failed to acquire expired slot')
        before = self.probe()
        old.release()
        data = old.result()
        if op == 'lookup':
            require(data['result']['verdicts'] == [None], 'stale HTTP completion was presented as a verdict')
            require(data['metrics']['http'] == 1, 'stale HTTP fixture did not exercise transport')
        else:
            require('ticket' not in data['result'], 'stale claimant returned an obsolete ticket')
            require(data['metrics']['http'] == 0, 'stale reservation reached HTTP')
        after = self.probe()
        require(self.slot_fingerprints(before) == self.slot_fingerprints(after), 'old worker overwrote replacement slot')
        require(any(lease['owner'] == replacement['ticket']['owner'] for lease in after['state']['leases']),
                'old worker released replacement lease')
        if phase == 'after_http':
            self.finish(replacement['ticket'])

    def stale_claim(self):
        self.reset(600)
        old = self.call('admit', prefix='01040')['result']
        require('ticket' in old, 'stale-claim fixture admission failed')
        self.call('expire')
        newer = self.call('admit', prefix='41040')['result']
        require('ticket' in newer, 'stale-claim replacement failed')
        before = self.probe()
        data = self.call('claim', ticket=old['ticket'])
        require(data['result']['claim'] is False, 'old ticket reclaimed newer slot')
        require(self.slot_fingerprints(before) == self.slot_fingerprints(self.probe()), 'old claim altered newer slot')
        self.finish(newer['ticket'])

    def killed_reservation(self, phase):
        self.reset(600)
        password = 'synthetic-killed-owner-fixture'
        prefix = hashlib.sha1(password.encode()).hexdigest()[:5].upper()
        worker = self.spawn('lookup', prefix=prefix, password=password, pause=phase)
        worker.wait_file('paused.json')
        before = self.probe()['state']
        require(bool(before['leases']) and before['credit'] < 600, 'kill checkpoint did not spend allowance')
        worker.kill()
        require(self.probe()['state']['credit'] == before['credit'], 'worker death refunded credit')
        self.call('expire')
        replacement = self.call('admit', prefix=prefix)['result']
        require('ticket' in replacement, 'expired killed lease did not recover')
        require(self.probe()['state']['credit'] < before['credit'], 'replacement did not spend another credit')
        self.finish(replacement['ticket'])

    def publisher_rollback(self):
        self.reset(600)
        admission = self.call('admit', prefix='02050')['result']
        require('ticket' in admission, 'publisher fixture admission failed')
        before = self.probe()
        worker = self.spawn('finish', ticket=admission['ticket'], body='0' * 35 + ':0', pause='publisher_locked')
        worker.wait_file('paused.json')
        # A separate connection must not observe the uncommitted control CAS.
        during = self.probe()
        require(before['rows'] == during['rows'], 'uncommitted publisher became visible to another worker')
        worker.kill()
        require(before['rows'] == self.probe()['rows'], 'SIGKILL did not roll back publication transaction')
        self.finish(admission['ticket'], '0' * 35 + ':0')

    def cache_paths(self):
        self.reset(600)
        password = 'synthetic-cache-hit-fixture'
        miss = self.call('lookup', password=password)
        require(miss['result']['verdicts'] == [True] and miss['metrics']['http'] == 1, 'cold lookup did not validate synthetic breach response')
        self.record_performance('cold_miss', miss)
        self.call('seed', credit=0)
        before = self.probe()
        self.call('flush')
        after = self.probe()
        require(before['rows'] == after['rows'], 'cache eviction altered authoritative rows')
        hit = self.call('lookup', password=password, count=100)
        require(all(value is True for value in hit['result']['verdicts']), 'cached breach was lost under exhaustion/eviction')
        require(hit['metrics']['http'] == 0 and hit['metrics']['store_writes'] == 0, 'cache hits opened transport or mutated store')
        self.record_performance('cached_hit_100', hit)
        denied = self.call('lookup', password='synthetic-unrelated-denial-fixture', count=100, alternate=True)
        require(all(value is None for value in denied['result']['verdicts']), 'denial became a clean verdict')
        require(denied['metrics']['http'] == 0 and denied['metrics']['store_writes'] <= 1, 'denials caused HTTP or per-attempt evidence writes')
        self.record_performance('exhausted_100', denied)
        self.report['persistent_cache'] = before['persistent_cache']
        passive = self.call('evidence')
        require(passive['metrics']['http'] == 0 and passive['metrics']['store_writes'] == 0, 'passive evidence mutated store or called HTTP')
        noop = self.call('noop')
        require(noop['bootstrap']['store_reads'] == 0 and noop['bootstrap']['store_writes'] == 0
                and noop['bootstrap']['http'] == 0, 'ordinary bootstrap touched HIBP authority/transport')
        self.record_performance('no_screening_bootstrap', noop)

    def storage(self, count):
        self.reset()
        data = self.call('storage', count=count, timeout=240)
        rows = data['result']['rows']
        require(len(rows) == 65, 'storage exercise did not cover all 64 slots plus control')
        require(sum(row['bytes'] for row in rows) <= 64 * 132096 + 4096, 'aggregate encoded bound exceeded')
        self.record_performance('storage_' + str(count), data)

    def scope(self):
        self.reset(0)
        data = self.call('scope')
        self.report['multisite'] = data['result']['multisite']

    def close(self):
        for child in self.children:
            child.kill()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp', required=True)
    parser.add_argument('--output', required=True, help='Sanitized JSON report path')
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
            'last_credit': lab.last_credit,
            'cap_two': lab.cap_two,
            'same_slot': lab.same_slot,
            'stale_reservation': lambda: lab.stale('after_reservation'),
            'stale_http': lambda: lab.stale('after_http'),
            'stale_claim': lab.stale_claim,
            'kill_reservation': lambda: lab.killed_reservation('after_reservation'),
            'kill_http': lambda: lab.killed_reservation('after_http'),
            'publisher_rollback': lab.publisher_rollback,
            'cache_paths': lab.cache_paths,
            'storage': lambda: lab.storage(args.storage_count),
            'scope': lab.scope,
        }
        selected = list(cases) if args.cases == 'all' else args.cases.split(',')
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
            # Fixed-key cleanup only. Never invoke WordPress/plugin uninstall:
            # other plugin options and unrelated data belong to the lab owner.
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
