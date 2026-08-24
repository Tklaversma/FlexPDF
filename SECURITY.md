# Security policy

**Please do not report security issues publicly.** Do not open a public
issue, discussion or pull request for anything that could be a
vulnerability.

## Reporting a vulnerability

Use **GitHub's private vulnerability reporting**: go to this repository's
Security tab and click "Report a vulnerability". That opens a private
advisory only the maintainer can see, with a workflow for confirming the
problem, coordinating a fix and, if needed, assigning a CVE.

You will get a first response within a few days. Once a fix is released,
the advisory is published so users know to update.

## What counts

FlexPDF renders HTML that you control into PDF, inside your PHP process.
Things that are security issues here:

- A document that reads a file outside the configured `base_path`.
- A document that makes a network request when remote images are off, or to
  a host that is not on the allowlist when they are on.
- A document that gets around the safety limits (`max_pages`, `max_depth`,
  `timeout_seconds` and the others in `config/flexpdf.php`) and consumes
  unbounded CPU or memory.
- Encryption that does not hold: a document that opens without its password,
  or permissions a reader does not enforce because of how the file was
  written.
- Output that contains something the input did not ask for.

Things that are not: a hostile document that renders slowly or produces many
pages while staying inside the limits. The README's "Rendering untrusted
HTML" section says what the limits promise and what they do not.

## Supported versions

Fixes go into the latest release. While FlexPDF is in beta there are no
maintained older lines, so please stay on the newest version.
