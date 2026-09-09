# Security Policy

## Scope

This library re-implements the removed PHP `mssql_*` functions on top of PDO. Like the original extension, it sends the SQL your application builds to the server **verbatim**: it does not, and cannot, add parameter binding or escaping to existing code. SQL injection in the calling application is therefore out of scope for this project; please fix it in the application.

In scope are issues in the library itself, for example:

 - a way to make the wrapper execute something other than the SQL it was given;
 - crashes or information leaks (e.g. credentials in error messages) caused by the wrapper;
 - incorrect handling of connection or result identifiers that lets code reach another connection's data.

## Supported versions

Only the latest 0.3.x release receives fixes. Versions 0.2.x and earlier have known error-handling defects and should be upgraded.

## Reporting

Please report vulnerabilities privately via GitHub's ["Report a vulnerability"](https://github.com/hexblot/mssql-compat/security/advisories/new) form rather than in a public issue. You should receive an acknowledgement within a week.
