# Password/HIBP extraction: lab verification

2026-10-14. Disposable WordPress Studio single-site lab, PHP 8.4; Keel loaded from
a symlink to the extraction branch. Reflection confirmed password validation and
HIBP lookup came from the new modules. No production settings changed.

All outbound HTTP was intercepted locally. HIBP received synthetic clean,
breached, malformed and unreachable responses; no request went to the real
service. Mail delivery was short-circuited. Only fabricated lab credentials were
used. The lab was stopped and removed after verification.

## Outcomes

- Real Administrator and Subscriber roles retained their different length-policy
  treatment. A short administrator password was refused, while the Subscriber
  length exemption remained effective.
- The real profile and reset hooks both validated the trimmed password and
  produced the expected short-password error.
- A request through WordPress's actual REST users controller returned HTTP 400
  for a weak password and left the stored password unchanged. A valid REST
  update returned HTTP 200 and the new password actually verified against the
  persisted user hash.
- A synthetic breached password was refused even for the exempt Subscriber.
- Intercepted HIBP URLs contained only five hexadecimal prefix characters; the
  Add-Padding header and 128 KiB response bound were preserved.
- A valid clean response returned false and was reused from cache. A malformed
  response returned null and populated failure diagnostics. A simulated outage
  failed open, and a subsequent fresh successful response cleared the failure.
- The HIBP opt-out prevented lookup entirely.

The final run completed without PHP warnings. An earlier CLI harness printed
results before a successful password update, causing cookie-header warnings;
collecting results until after the update corrected that harness issue.

This pass exercised real hooks and REST dispatch, not browser profile/reset forms
or an external HIBP endpoint. Existing standalone tests cover response truncation,
body validation, padded zero-count rows, cache behavior, role scoping and REST
argument/context handling. All moved function bodies remained unchanged.
