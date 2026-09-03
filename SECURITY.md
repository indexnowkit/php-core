# Security

The only sensitive value handled by this library is the IndexNow key. It is public by design (search engines fetch it
from `/{key}.txt`), but anyone holding it can submit arbitrary URLs of your host. Keep it in the environment, never commit
it, rotate it by changing `INDEXNOW_KEY`. Logs and exception messages produced by this library mask keys to 4 characters.

What the library does to stay safe inside your application:

- Only `http`/`https` URLs are submitted; credentials, control characters and oversized hosts are rejected before any request.
- Custom endpoints must be `https` (the key travels in the request body).
- `SitemapReader` follows nested sitemaps only on the host of the root sitemap, caps recursion depth, document count and
  gzip output, and disables external entities and network access in the XML parser.
- HTTP bodies are capped (2 KiB for submissions, 50 MiB for sitemaps); discovered clients get a timeout and no redirects.
- Remote failures never throw into your code: they become `Result` objects and log entries.

Report vulnerabilities privately via [GitHub security advisories](https://github.com/indexnowkit/php/security/advisories/new)
or to i.pinchuk.work@gmail.com. Please do not open public issues for security reports.
