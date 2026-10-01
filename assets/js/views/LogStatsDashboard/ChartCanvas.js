// @flow
import React from 'react';
import {BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip} from 'chart.js';
import {fontFamily, ink} from './palette';
import styles from './logStatsDashboard.scss';

// only the pieces the dashboard draws, so that the admin bundle stays small
Chart.register(BarController, BarElement, CategoryScale, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);
Chart.defaults.font.family = fontFamily;
Chart.defaults.color = ink.secondary;

type Props = {|
    data: Object,
    label: string,
    options?: Object,
    type: 'bar' | 'line',
|};

/**
 * A Chart.js chart kept in sync with its props: the chart is created once, then updated in place.
 */
export default class ChartCanvas extends React.Component<Props> {
    canvas: ?HTMLCanvasElement;
    chart: ?Chart;

    componentDidMount() {
        const {canvas} = this;
        if (!canvas) {
            return;
        }

        this.chart = new Chart(canvas, {type: this.props.type, data: this.props.data, options: this.options()});
    }

    componentDidUpdate(prevProps: Props) {
        const {chart} = this;
        if (!chart || (prevProps.data === this.props.data && prevProps.options === this.props.options)) {
            return;
        }

        chart.data = this.props.data;
        chart.options = this.options();
        chart.update();
    }

    componentWillUnmount() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = undefined;
        }
    }

    options(): Object {
        const {options = {}} = this.props;
        const axis = {
            border: {color: ink.axis},
            grid: {color: ink.grid, drawTicks: false},
            ticks: {color: ink.muted, padding: 6},
        };

        return {
            animation: false,
            interaction: {intersect: false, mode: 'index'},
            maintainAspectRatio: false,
            responsive: true,
            ...options,
            plugins: {
                legend: {align: 'start', labels: {boxHeight: 10, boxWidth: 10, color: ink.secondary}, position: 'top'},
                tooltip: {boxPadding: 4},
                ...options.plugins,
            },
            scales: this.scales(axis),
        };
    }

    scales(axis: Object): Object {
        const {options = {}} = this.props;
        const value = {...axis, beginAtZero: true, ticks: {...axis.ticks, precision: 0}};

        const scales = {
            // horizontal labels, some skipped when they don't fit, rather than a slanted crowd
            x: this.scale({...axis, grid: {display: false}, ticks: {...axis.ticks, autoSkipPadding: 12, maxRotation: 0}}, 'x'),
            // counts and milliseconds are whole numbers: no 0.2 tick shown as a rounded duplicate
            y: this.scale(value, 'y'),
        };

        // an extra value axis (eg: a second one on the right) keeps its grid off the plot: one grid only
        Object.keys(options.scales || {}).filter((name) => !(name in scales)).forEach((name) => {
            scales[name] = this.scale({...value, grid: {...value.grid, drawOnChartArea: false}}, name);
        });

        return scales;
    }

    /**
     * An axis of the chart: the shared defaults, then what the chart sets, ticks merged rather than replaced.
     */
    scale(defaults: Object, axis: string): Object {
        const {options = {}} = this.props;
        const own = (options.scales || {})[axis] || {};

        return {...defaults, ...own, ticks: {...defaults.ticks, ...own.ticks}};
    }

    setCanvas = (canvas: ?HTMLCanvasElement) => {
        this.canvas = canvas;
    };

    render() {
        return (
            <div className={styles.chart}>
                <canvas aria-label={this.props.label} ref={this.setCanvas} role="img" />
            </div>
        );
    }
}
