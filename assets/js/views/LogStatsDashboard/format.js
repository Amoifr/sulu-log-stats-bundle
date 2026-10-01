// @flow
import {transformBytesToReadableString, translate} from 'sulu-admin-bundle/utils';

export type Percentile = {|boundMs: number, beyond: boolean|};

const numberFormat = new Intl.NumberFormat();
const dayFormat = new Intl.DateTimeFormat(undefined, {day: 'numeric', month: 'short', timeZone: 'UTC'});

export function formatNumber(value: number): string {
    return numberFormat.format(value);
}

export function formatPercent(part: number, total: number): string {
    return total > 0 ? numberFormat.format(Math.round(part / total * 1000) / 10) + ' %' : '–';
}

export function formatBytes(value: number): string {
    return transformBytesToReadableString(value);
}

export function formatMs(value: ?number): string {
    return value === undefined || value === null ? '–' : numberFormat.format(Math.round(value)) + ' ms';
}

/**
 * A percentile read from the histogram is a bucket bound: the requests took at most that long.
 */
export function formatPercentile(percentile: ?Percentile): string {
    if (!percentile) {
        return '–';
    }

    return translate(
        percentile.beyond ? 'amoifr_log_stats.percentile_beyond' : 'amoifr_log_stats.percentile_at_most',
        {duration: formatMs(percentile.boundMs)}
    );
}

/**
 * A YYYY-MM-DD day, written short. Read in UTC so that no time zone shifts it to the day before.
 */
export function formatDay(day: string): string {
    return dayFormat.format(new Date(day + 'T00:00:00Z'));
}

export function shiftDay(day: string, days: number): string {
    const date = new Date(day + 'T00:00:00Z');
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}
