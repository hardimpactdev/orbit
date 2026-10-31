# GitHub App registration and observations

The Gateway owns the App identity, encrypted private key, pending browser
registration, API authorization and token signing. Swamp exchanges the one-time
manifest code and reads installation observations in one native root per intent.
The Gateway does not make these GitHub requests during install or show.

Registration runs on the admitted Gateway Node. Its code comes from declared
private material. The conversion reply stays in memory and the existing App key
goes directly to the declared secret-output store. Native results contain only
`registered: true`. Neither the code nor the App key enters workflow inputs,
resources, logs or history. A failed or uncertain exchange is not retried by the
Gateway.

Installation sync uses a short-lived App JWT minted by the Gateway through the
shared private reader. The signing key stays on the Gateway. This JWT is a new
credential exposure and requires the owning credential review before merge.
Swamp sends it only to the fixed GitHub installation endpoint, refuses
redirects, bounds pagination and response bytes, and records public observations
only. Install and show read the last stored observation and identify unknown or
stale state. Repository installation-token grants are unchanged.

Instance and Project dependency results already use Swamp `data.get`. Their
presentation caches and failure suppression remain read-only; no host action or
second dependency scanner is added.
