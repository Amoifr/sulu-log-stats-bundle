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
            scales: {
                x: {...axis, grid: {display: false}, ...(options.scales || {}).x},
                y: {...axis, beginAtZero: true, ...(options.scales || {}).y},
            },
        };
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
