/**
 * The client's one error shape — the org's shared version (territorio's
 * L1-25/L1-29 doctrine, promoted by Phase 12; farmacia's L2-14/L2-15
 * restatement pointed here).
 *
 * `messageFrom` is the idiom every catch block hand-rolled before this
 * existed — the server's Spanish sentence when there is one, a fallback
 * that does not pretend to otherwise when there is not — INCLUDING the
 * Blob case: a response requested as a Blob arrives as one in the error
 * body too, `data.message` is undefined there, and the subtlety existed
 * only because the shape was never factored. It is awaitable everywhere
 * (the Blob path makes it one on some callers' paths — so it is one on
 * every path, and consumers can always `await` it).
 *
 * `loggers(prefix)` returns the console half with the app's own label:
 * an AxiosError serialises its `config.data` — the request body — and a
 * failed save was putting the record's clinical contact data (or the
 * planilla's medication payload) into devtools for the page session.
 * The loggers print a stable label plus `exception.message` and the HTTP
 * status, never the object. Each app binds its prefix once in its own
 * src/api.js and re-exports — call sites never see the factory.
 */

export async function messageFrom(exception, fallback) {
	const data = exception?.response?.data

	if (typeof Blob !== 'undefined' && data instanceof Blob) {
		try {
			return JSON.parse(await data.text()).message ?? fallback
		} catch {
			return fallback
		}
	}

	return data?.message ?? fallback
}

export function loggers(prefix) {
	const line = (label, exception) => {
		const status = exception?.response?.status
		return `${prefix}: ${label} (${status ?? 'sin respuesta'})`
	}

	return {
		warnError(label, exception) {
			console.warn(line(label, exception), exception?.message ?? String(exception))
		},
		safeError(label, exception) {
			console.error(line(label, exception), exception?.message ?? String(exception))
		},
	}
}
