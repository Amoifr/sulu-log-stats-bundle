// @flow
/**
 * Chart colors, validated with the dataviz palette checks against the chart surface:
 * - the two-series charts take the first two categorical slots (blue, orange);
 * - status classes carry a state: 2xx good, 4xx warning, 5xx critical, while redirects (and the rare
 *   1xx) are an identity, in categorical blue. The 4xx yellow sits below 3:1 on the surface and the
 *   4xx/5xx pair is at the tritan floor, so the stack keeps 2px gaps, a labelled legend and the
 *   daily table view.
 */
export const surface = '#fcfcfb';

export const ink = {
    primary: '#0b0b0b',
    secondary: '#52514e',
    muted: '#898781',
    grid: '#e1e0d9',
    axis: '#c3c2b7',
};

export const series = ['#2a78d6', '#eb6834'];

export const statusClasses = {
    success: '#0ca30c',
    redirect: '#2a78d6',
    clientError: '#eda100',
    serverError: '#d03b3b',
};

export const fontFamily = 'system-ui, -apple-system, "Segoe UI", sans-serif';
