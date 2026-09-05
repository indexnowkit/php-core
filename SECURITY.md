# Security

The only sensitive value handled by this library is the IndexNow key. It is public by design (search engines fetch it
from `/{key}.txt`), but anyone holding it can submit arbitrary URLs of your host. Keep it in the environment, never commit
it, rotate it by changing `INDEXNOW_KEY`. Logs and exception messages produced by this library mask keys to 4 characters.

What the library does to stay safe inside your application:

- Only `http`/`https` URLs are submitted; URLs with credentials, control characters or oversized hosts are rejected before
  any request (`base_url`, `key_location` and custom endpoints with credentials are rejected at configuration time).
- With a default `key` and no `hosts` map, every host you submit is sent under that key. Submitting URLs taken from
  untrusted input therefore makes your application announce foreign hosts under your key; the engines reject them
  (403/422) but the requests still happen. Submit only URLs of your own hosts, or list them in `hosts`.
- Custom endpoints must be `https` (the key travels in the request body).
- The core fetches only the key files of the hosts it holds keys for (`check`) and the endpoints it submits to; it
  never follows a URL taken from a document. Add-on packages that read documents through the transport document
  their own rules.
- A response shorter than its `Content-Length`, or a connection lost mid-body, is a `TransportException`, never a
  document.
- HTTP bodies are capped (2 KiB for submissions, 50 MiB for documents read through `get()`/`download()`). `symfony/http-client` and Guzzle created by
  `Psr18Transport::discover()` get the timeout and no redirects; any other discovered or injected client keeps its own
  settings, so configure the timeout there.
- Remote failures never throw into your code: they become `Result` objects and log entries.

Report vulnerabilities privately via [GitHub security advisories](https://github.com/indexnowkit/php/security/advisories/new)
or to i.pinchuk.work@gmail.com. Please do not open public issues for security reports. Reports are acknowledged within 5 business days; a fix or a mitigation plan follows within 30 days.
