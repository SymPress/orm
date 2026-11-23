# Security Policy

## Supported Versions

Security fixes are provided for the latest released version of this package.

## Reporting a Vulnerability

Please do not open public issues for suspected security vulnerabilities.

Report vulnerabilities by emailing `brian.schaeffner@sympress.io` with:

- A description of the issue and its impact
- Steps to reproduce or a minimal proof of concept
- Affected versions or commits, if known
- Any relevant logs with secrets removed

You should receive an acknowledgement within 72 hours. Confirmed vulnerabilities
will be handled with coordinated disclosure.

## Query Fragments

`select()`, `where()`, `having()`, `groupBy()`, join conditions, `Criteria`
expressions and DQL strings are SQL expression fragments. The ORM rewrites
mapped field paths and parameters in them and rejects statement separators,
subselects and comments, but it cannot tell a value from SQL. Never interpolate
request data into them: bind every value as a parameter, and map user-chosen
sort fields through an allow-list. See "Security Notes" in
[docs/api-and-architecture.md](docs/api-and-architecture.md).
