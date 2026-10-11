import { translate as t } from '@nextcloud/l10n'

/**
 * The reader-facing sentence for a feed error the server reported.
 *
 * The server's feed errors are diagnostic English ("HTTP 403", "cURL error 28",
 * "circuit breaker open") built from upstream responses, so they are matched
 * here and replaced by a translated sentence rather than shown as-is. Shared
 * by the feed widget and the admin's "Test connection" so both say the same
 * thing about the same failure.
 *
 * @param {string} err the `error` the server returned
 * @param {string} sourceType the widget's source type ('rss', 'connection', …)
 * @return {string}
 */
export function feedErrorMessage(err, sourceType) {
	const e = String(err || '')
	if (e.includes('inactive') || e.includes('disabled')) {
		return t('intravox', 'This connection is currently disabled by an administrator.')
	} else if (e.includes('not found') || e.includes('404')) {
		return t('intravox', 'Connection no longer exists. Please reconfigure this widget.')
	} else if (e.includes('token') || e.includes('401') || e.includes('Authentication')) {
		return t('intravox', 'Authentication required. Please connect your account.')
	} else if (e.includes('403') || e.includes('Access denied')) {
		// An RSS 403 is a different problem from a connection 403: the
		// source is up, it refuses this server. Measured on dev — five of
		// the dashboard's feeds answer 200 from a home connection and 403
		// from the datacenter IP, whatever User-Agent is sent. Telling an
		// admin to "check the permissions" of a public feed sends them
		// looking for something that does not exist.
		return sourceType === 'rss'
			? t('intravox', 'This source refuses requests from this server. Nothing to fix here — the feed blocks datacenter addresses.')
			: t('intravox', 'Access denied. Check the connection permissions.')
	} else if (e.includes('429') || e.includes('Rate limited')) {
		return t('intravox', 'Too many requests. Please try again later.')
	} else if (e.includes('timed out') || e.includes('timeout') || e.includes('cURL error 28')) {
		return t('intravox', 'The source did not respond in time.')
	} else if (e.includes('too large')) {
		return t('intravox', 'This feed is too large to process.')
	} else if (e.includes('SSL') || e.includes('cURL error')) {
		return t('intravox', 'Could not reach the source. The connection failed.')
	} else if (e.includes('circuit breaker')) {
		return t('intravox', 'This source failed repeatedly and is paused. It retries automatically.')
	}
	return t('intravox', 'Could not load feed. Check the connection settings.')
}
