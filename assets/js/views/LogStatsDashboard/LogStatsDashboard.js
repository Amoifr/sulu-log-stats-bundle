// @flow
import React from 'react';
import {Loader} from 'sulu-admin-bundle/components';
import {Requester} from 'sulu-admin-bundle/services';
import {buildQueryString, translate} from 'sulu-admin-bundle/utils';
import ChartCanvas from './ChartCanvas';
import {formatBytes, formatDay, formatMs, formatNumber, formatPercent, formatPercentile, shiftDay} from './format';
import type {Percentile} from './format';
import {series, statusClasses, surface} from './palette';
import styles from './logStatsDashboard.scss';

type ResponseTime = {|averageMs: number, histogram: {[string]: number}, p95: ?Percentile, requests: number|};
type StatusClasses = {[string]: number};
type Day = {|
    botRequests: number,
    bytes: number,
    day: string,
    pageViews: number,
    requests: number,
    responseTime: ?ResponseTime,
    statusClasses: StatusClasses,
    visitors: number,
|};
type Ranked = Array<{|count: number, path: string|}>;
type Statistics = {|
    days: Array<Day>,
    from: string,
    pageViewsByHourOfDay: Array<number>,
    responseTime: ?ResponseTime,
    timezone: string,
    to: string,
    topNotFound: Ranked,
    topPages: Ranked,
    topServerErrors: Ranked,
    totals: {|botRequests: number, bytes: number, pageViews: number, requests: number, statusClasses: StatusClasses, visitors: number|},
|};

type Props = {
    router: Object,
};

type State = {|
    data: ?Statistics,
    error: ?string,
    from: string,
    loading: boolean,
    to: string,
    today: ?string,
|};

const PRESETS = [7, 30, 90, 365];

/**
 * The "Statistics" view of the admin: the aggregates of the server logs over a period of days.
 */
export default class LogStatsDashboard extends React.Component<Props, State> {
    state: State = {data: undefined, error: undefined, from: '', loading: true, to: '', today: undefined};

    componentDidMount() {
        // without a period, the API answers with the last 30 days up to its own today
        this.load();
    }

    get apiUrl(): ?string {
        const {route} = this.props.router;

        return route && route.options ? route.options.apiUrl : undefined;
    }

    load(from?: string, to?: string) {
        const {apiUrl} = this;
        if (!apiUrl) {
            this.setState({error: translate('amoifr_log_stats.error_routes_missing'), loading: false});

            return;
        }

        this.setState({error: undefined, loading: true});

        Requester.get(apiUrl + buildQueryString(from && to ? {from, to} : {}))
            .then((data: Statistics) => {
                this.setState((state) => ({
                    data,
                    from: data.from,
                    loading: false,
                    to: data.to,
                    today: state.today || data.to,
                }));
            })
            .catch((response) => {
                if (response && typeof response.json === 'function') {
                    response.json()
                        .then((body) => this.setState({error: body.message, loading: false}))
                        .catch(() => this.setState({error: translate('amoifr_log_stats.error_loading'), loading: false}));

                    return;
                }

                this.setState({error: translate('amoifr_log_stats.error_loading'), loading: false});
            });
    }

    handlePreset = (days: number) => {
        const {today} = this.state;
        if (today) {
            this.load(shiftDay(today, 1 - days), today);
        }
    };

    handleFromChange = (event: SyntheticInputEvent<HTMLInputElement>) => {
        this.setState({from: event.currentTarget.value});
    };

    handleToChange = (event: SyntheticInputEvent<HTMLInputElement>) => {
        this.setState({to: event.currentTarget.value});
    };

    handleRangeSubmit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        const {from, to} = this.state;
        if (from && to) {
            this.load(from, to);
        }
    };

    activePreset(): ?number {
        const {data, today} = this.state;
        if (!data || !today || data.to !== today) {
            return undefined;
        }

        return PRESETS.find((days) => shiftDay(today, 1 - days) === data.from);
    }

    renderToolbar() {
        const {data, from, to} = this.state;
        const activePreset = this.activePreset();

        return (
            <div className={styles.toolbar}>
                <div className={styles.presets}>
                    {PRESETS.map((days) => (
                        <button
                            className={days === activePreset ? styles.preset + ' ' + styles['preset-active'] : styles.preset}
                            key={days}
                            onClick={() => this.handlePreset(days)}
                            type="button"
                        >
                            {translate('amoifr_log_stats.last_days', {count: days})}
                        </button>
                    ))}
                </div>
                <form className={styles.range} onSubmit={this.handleRangeSubmit}>
                    <input aria-label={translate('amoifr_log_stats.from')} onChange={this.handleFromChange} type="date" value={from} />
                    <span>→</span>
                    <input aria-label={translate('amoifr_log_stats.to')} onChange={this.handleToChange} type="date" value={to} />
                    <button className={styles.preset} type="submit">{translate('amoifr_log_stats.apply')}</button>
                </form>
                {data && (
                    <span className={styles.timezone}>
                        {translate('amoifr_log_stats.timezone', {timezone: data.timezone})}
                    </span>
                )}
            </div>
        );
    }

    renderTiles(data: Statistics) {
        const {totals, responseTime} = data;
        const classes = totals.statusClasses;
        const tiles = [
            ['page_views', formatNumber(totals.pageViews)],
            ['visitors', formatNumber(totals.visitors), translate('amoifr_log_stats.visitors_hint')],
            ['requests', formatNumber(totals.requests)],
            ['bot_share', formatPercent(totals.botRequests, totals.requests)],
            ['client_errors', formatNumber(classes['4'] || 0), formatPercent(classes['4'] || 0, totals.requests)],
            ['server_errors', formatNumber(classes['5'] || 0), formatPercent(classes['5'] || 0, totals.requests)],
            [
                'average_response_time',
                formatMs(responseTime ? responseTime.averageMs : undefined),
                responseTime ? undefined : translate('amoifr_log_stats.no_durations_short'),
            ],
            [
                'p95_response_time',
                formatPercentile(responseTime ? responseTime.p95 : undefined),
                responseTime ? undefined : translate('amoifr_log_stats.no_durations_short'),
            ],
            ['bytes', formatBytes(totals.bytes)],
        ];

        return (
            <div className={styles.tiles}>
                {tiles.map(([key, value, hint]) => (
                    <div className={styles.tile} key={key}>
                        <span className={styles['tile-label']}>{translate('amoifr_log_stats.' + key)}</span>
                        <span className={styles['tile-value']}>{value}</span>
                        {hint && <span className={styles['tile-hint']}>{hint}</span>}
                    </div>
                ))}
            </div>
        );
    }

    renderCharts(data: Statistics) {
        const labels = data.days.map((day) => formatDay(day.day));
        const line = (label, values, color) => ({
            backgroundColor: color,
            borderColor: color,
            borderWidth: 2,
            data: values,
            label,
            pointHoverBorderColor: surface,
            pointHoverBorderWidth: 2,
            pointHoverRadius: 5,
            pointRadius: 0,
            tension: 0,
        });
        // a 2px surface line on top of each segment keeps stacked fills apart
        const stack = (label, key, color) => ({
            backgroundColor: color,
            borderColor: surface,
            borderSkipped: 'start',
            borderWidth: {top: 2},
            data: data.days.map((day) => day.statusClasses[key] || 0),
            label,
            stack: 'status',
        });

        const traffic = {
            datasets: [
                line(translate('amoifr_log_stats.page_views'), data.days.map((day) => day.pageViews), series[0]),
                {
                    ...line(translate('amoifr_log_stats.visitors'), data.days.map((day) => day.visitors), series[1]),
                    yAxisID: 'visitors',
                },
            ],
            labels,
        };

        // page views on the left axis, visitors on the right one: each axis is named after its series and
        // drawn in its color, so that no value is read against the wrong scale
        const axisTitle = (text) => ({display: true, text});
        const trafficOptions = {
            scales: {
                visitors: {
                    border: {color: series[1], width: 2},
                    position: 'right',
                    title: axisTitle(translate('amoifr_log_stats.visitors')),
                },
                y: {
                    border: {color: series[0], width: 2},
                    title: axisTitle(translate('amoifr_log_stats.page_views')),
                },
            },
        };

        const statuses = {
            datasets: [
                stack(translate('amoifr_log_stats.status_success'), '2', statusClasses.success),
                {
                    ...stack(translate('amoifr_log_stats.status_redirect'), '3', statusClasses.redirect),
                    data: data.days.map((day) => (day.statusClasses['3'] || 0) + (day.statusClasses['1'] || 0)),
                },
                stack(translate('amoifr_log_stats.status_client_error'), '4', statusClasses.clientError),
                stack(translate('amoifr_log_stats.status_server_error'), '5', statusClasses.serverError),
            ],
            labels,
        };

        const hours = {
            datasets: [{
                backgroundColor: series[0],
                borderRadius: {topLeft: 4, topRight: 4},
                borderSkipped: 'start',
                data: data.pageViewsByHourOfDay,
                label: translate('amoifr_log_stats.page_views'),
            }],
            labels: data.pageViewsByHourOfDay.map((_, hour) => hour + ' h'),
        };

        const responseTimes = {
            datasets: [
                line(
                    translate('amoifr_log_stats.average_response_time'),
                    data.days.map((day) => day.responseTime ? Math.round(day.responseTime.averageMs) : null),
                    series[0]
                ),
                line(
                    translate('amoifr_log_stats.p95_response_time'),
                    data.days.map((day) => day.responseTime && day.responseTime.p95 ? day.responseTime.p95.boundMs : null),
                    series[1]
                ),
            ],
            labels,
        };

        const milliseconds = {scales: {y: {ticks: {callback: (value) => formatMs(value), precision: 0}}}};
        const hasDurations = data.days.some((day) => !!day.responseTime);

        return (
            <div className={styles.charts}>
                <section className={styles.card}>
                    <h3>{translate('amoifr_log_stats.chart_traffic')}</h3>
                    <ChartCanvas
                        data={traffic}
                        label={translate('amoifr_log_stats.chart_traffic')}
                        options={trafficOptions}
                        type="line"
                    />
                </section>
                <section className={styles.card}>
                    <h3>{translate('amoifr_log_stats.chart_status')}</h3>
                    <ChartCanvas
                        data={statuses}
                        label={translate('amoifr_log_stats.chart_status')}
                        options={{scales: {x: {stacked: true}, y: {stacked: true}}}}
                        type="bar"
                    />
                </section>
                <section className={styles.card}>
                    <h3>{translate('amoifr_log_stats.chart_hours')}</h3>
                    <ChartCanvas
                        data={hours}
                        label={translate('amoifr_log_stats.chart_hours')}
                        options={{plugins: {legend: {display: false}}}}
                        type="bar"
                    />
                </section>
                <section className={styles.card}>
                    <h3>{translate('amoifr_log_stats.chart_response_time')}</h3>
                    {hasDurations
                        ? (
                            <ChartCanvas
                                data={responseTimes}
                                label={translate('amoifr_log_stats.chart_response_time')}
                                options={milliseconds}
                                type="line"
                            />
                        )
                        : <span className={styles.empty}>{translate('amoifr_log_stats.no_durations')}</span>
                    }
                </section>
            </div>
        );
    }

    renderRanking(title: string, rows: Ranked) {
        return (
            <section className={styles.card}>
                <h3>{title}</h3>
                {rows.length === 0
                    ? <span className={styles.empty}>{translate('amoifr_log_stats.nothing')}</span>
                    : (
                        <table className={styles.table}>
                            <thead>
                                <tr>
                                    <th>{translate('amoifr_log_stats.path')}</th>
                                    <th className={styles.number}>{translate('amoifr_log_stats.count')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.path}>
                                        <td className={styles.path}>{row.path}</td>
                                        <td className={styles.number}>{formatNumber(row.count)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )
                }
            </section>
        );
    }

    renderDailyTable(data: Statistics) {
        const columns = ['requests', 'page_views', 'visitors', 'status_success', 'status_redirect', 'status_client_error', 'status_server_error', 'average_response_time', 'p95_response_time'];

        return (
            <details className={styles.card + ' ' + styles.details}>
                <summary>{translate('amoifr_log_stats.daily_table')}</summary>
                <div className={styles.scroll}>
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                <th>{translate('amoifr_log_stats.day')}</th>
                                {columns.map((column) => (
                                    <th className={styles.number} key={column}>{translate('amoifr_log_stats.' + column)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {data.days.map((day) => (
                                <tr key={day.day}>
                                    <td>{formatDay(day.day)}</td>
                                    <td className={styles.number}>{formatNumber(day.requests)}</td>
                                    <td className={styles.number}>{formatNumber(day.pageViews)}</td>
                                    <td className={styles.number}>{formatNumber(day.visitors)}</td>
                                    <td className={styles.number}>{formatNumber(day.statusClasses['2'] || 0)}</td>
                                    <td className={styles.number}>
                                        {formatNumber((day.statusClasses['3'] || 0) + (day.statusClasses['1'] || 0))}
                                    </td>
                                    <td className={styles.number}>{formatNumber(day.statusClasses['4'] || 0)}</td>
                                    <td className={styles.number}>{formatNumber(day.statusClasses['5'] || 0)}</td>
                                    <td className={styles.number}>
                                        {formatMs(day.responseTime ? day.responseTime.averageMs : undefined)}
                                    </td>
                                    <td className={styles.number}>
                                        {formatPercentile(day.responseTime ? day.responseTime.p95 : undefined)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        );
    }

    render() {
        const {data, error, loading} = this.state;

        return (
            <div className={styles.dashboard}>
                {this.apiUrl && this.renderToolbar()}
                {error && <div className={styles.message}>{error}</div>}
                {loading && <Loader />}
                {!loading && data && data.totals.requests === 0 && !data.responseTime && (
                    <div className={styles.message}>{translate('amoifr_log_stats.no_data')}</div>
                )}
                {!loading && data && this.renderTiles(data)}
                {!loading && data && this.renderCharts(data)}
                {!loading && data && (
                    <div className={styles.tables}>
                        {this.renderRanking(translate('amoifr_log_stats.top_pages'), data.topPages)}
                        {this.renderRanking(translate('amoifr_log_stats.top_not_found'), data.topNotFound)}
                        {this.renderRanking(translate('amoifr_log_stats.top_server_errors'), data.topServerErrors)}
                    </div>
                )}
                {!loading && data && this.renderDailyTable(data)}
            </div>
        );
    }
}
