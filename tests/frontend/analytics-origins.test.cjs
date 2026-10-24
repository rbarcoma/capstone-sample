// Render the real views with isolated UI/chart/Inertia adapters; never call a workflow.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const ts = require('typescript');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const root = path.resolve(__dirname, '../..');
const definitions = require('../../resources/forecast-metrics.json');

function renderPage(relative, props, stateOverrides = {}) {
    const cards = [], charts = [], controls = [];
    let stateIndex = 0;
    const container = ({ children }) => React.createElement('div', null, children);
    const hooks = { ...React, useEffect: () => {}, useMemo: (callback) => callback(),
        useState: (value) => {
            const index = stateIndex++;
            return [index in stateOverrides ? stateOverrides[index] : value, () => {}];
        } };
    function load(file) {
        const filename = path.join(root, file);
        const source = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
            fileName: filename, compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
        }).outputText;
        const module = { exports: {} };
        const localRequire = (id) => {
            if (id === 'react') return hooks;
            if (id === 'react/jsx-runtime') return require(id);
            if (id === '@inertiajs/react') return { usePage: () => ({ props: { flash: {} } }), router: {} };
            if (id === '@/lib/display-value') return load('resources/js/lib/display-value.ts');
            if (id === '@/components/dss/stat-card') {
                const { StatCard } = load('resources/js/components/dss/stat-card.tsx');
                return { StatCard: (props) => { cards.push(props); return React.createElement(StatCard, props); } };
            }
            if (id === 'recharts') return new Proxy({}, { get: (_, name) => (props) => {
                charts.push({ component: name, ...props });
                return name === 'AreaChart' || name === 'LineChart'
                    ? React.createElement('svg', null, props.children) : React.createElement(container, props);
            } });
            if (id.startsWith('@/components/ui/')) return new Proxy({}, { get: (_, name) => (props) => {
                controls.push({ name, ...props }); return React.createElement(container, props);
            } });
            if (id === 'lucide-react') return new Proxy({}, { get: () => () => null });
            throw new Error(`Unexpected dependency: ${id}`);
        };
        vm.runInThisContext(`(function(require, module, exports) { ${source}\n})`, { filename })(localRequire, module, module.exports);
        return module.exports;
    }
    const Page = load(relative).default;
    return { html: renderToStaticMarkup(React.createElement(Page, props)), cards, charts, controls };
}

for (const role of ['Admin', 'User']) {
    const view = `resources/js/pages/${role}/Analytics.tsx`;
    test(`${role} Analytics keeps actual fitted and issued series distinct in the existing chart and tooltip`, () => {
        const row = { year: 2026, month: 9, period: '2026-09', label: '2026-09',
            actual: 1000, fitted: 900, issued_forecast: 1200, issuance_label: 'Recorded production time' };
        const result = renderPage(view, { actualPredictedTrend: [row], summary: {} });
        const lines = result.charts.filter(({ component }) => component === 'Line');
        assert.deepEqual(lines.map(({ dataKey }) => dataKey), ['actual', 'fitted', 'issued_forecast']);
        assert.deepEqual(lines.map((line) => line.name), ['Actual Data', 'Historical Fit (in-sample)', 'Issued Estimate']);
        assert.equal(result.controls.filter(({ name }) => name === 'Select').length, 2);
        assert.equal(result.controls.filter(({ name }) => name === 'Checkbox').length, 2);
        assert.equal(result.cards.length, 4);
        assert.equal(result.charts.filter(({ component }) => component === 'LineChart').length, 1);
        assert.ok(result.html.includes('all available actual data'));
        assert.ok(result.html.includes('filter only the comparison chart'));
        const tooltip = result.charts.find(({ component }) => component === 'Tooltip').content;
        const html = renderToStaticMarkup(React.cloneElement(tooltip, { active: true, payload: [{ payload: row }] }));
        for (const value of ['Historical Fit (in-sample)', 'Issued Estimate', '900 kWh', '1,200 kWh', 'Recorded production time']) assert.ok(html.includes(value));
        assert.ok(!html.includes('Accuracy'));
    });

    test(`${role} Analytics missing summaries and future actuals remain unavailable while zero survives`, () => {
        for (const value of [null, undefined, 0]) {
            const result = renderPage(view, { summary: { average_consumption: value, highest_consumption: value,
                average_peak_demand: value, average_solar_irradiance: value },
                actualPredictedTrend: [{ year: 2026, month: 10, label: '2026-10', actual: null, fitted: null, issued_forecast: 0 }] });
            assert.deepEqual(result.cards.map(({ value }) => value), value == null
                ? ['Unavailable', 'Unavailable', 'Unavailable', 'Unavailable'] : ['0 kWh', '0 kWh', '0 kW', '0']);
            const tooltip = result.charts.find(({ component }) => component === 'Tooltip').content;
            const html = renderToStaticMarkup(React.cloneElement(tooltip, { active: true, payload: [{ payload: { actual: null, fitted: null, issued_forecast: 0 } }] }));
            assert.equal((html.match(/Unavailable/g) || []).length, 2);
            assert.ok(html.includes('0 kWh'));
            assert.ok(!html.includes('Accuracy'));
        }
    });

    test(`${role} Analytics selection filters only comparison data and never fills missing aggregate values with zero`, () => {
        const result = renderPage(view, { actualPredictedTrend: [
            { month: 8, year: 2026, actual: 10 }, { month: 9, year: 2026, actual: 20 }],
            summary: { average_consumption: 15 }, yearlyComparison: [{ year: 2026, value: null }] }, { 1: '9' });
        const comparison = result.charts.find(({ component }) => component === 'LineChart');
        assert.deepEqual(comparison.data.map(({ month }) => month), [9]);
        assert.equal(result.cards[0].value, '15 kWh');
        const area = result.charts.find(({ component }) => component === 'AreaChart');
        assert.equal(area.data[0].value, null);
        const tooltips = result.charts.filter(({ component }) => component === 'Tooltip');
        const html = renderToStaticMarkup(tooltips[1].content({ active: true, payload: [{ value: null }], label: '2026' }));
        assert.ok(html.includes('Unavailable'));
        assert.ok(!html.includes('0 kWh'));
    });
}

for (const [view, title] of [['Admin/Forecasting', 'Dataset-next-month Estimate'], ['User/ForecastResults', 'Predicted Consumption']]) {
    test(`${view} cards and history distinguish missing predictions and changes from zero`, () => {
        const empty = renderPage(`resources/js/pages/${view}.tsx`, {
            latestForecast: null, forecastHistory: [], metricDefinitions: definitions,
        });
        assert.equal(empty.cards.find((card) => card.title === title).value, 'Unavailable');
        if (view.startsWith('User')) assert.equal(empty.cards.find((card) => card.title === 'Possible Change').value, 'Unavailable');
        for (const value of [null, undefined, 0]) {
            const forecast = { id: 1, year: 2026, month: 9, predicted_consumption_kwh: value, change_percent: value };
            const result = renderPage(`resources/js/pages/${view}.tsx`, { latestForecast: forecast,
                forecastHistory: [forecast], metricDefinitions: definitions });
            assert.equal(result.cards.find((card) => card.title === title).value, value == null ? 'Unavailable' : '0 kWh');
            if (view.startsWith('User')) assert.equal(result.cards.find((card) => card.title === 'Possible Change').value, value == null ? 'Unavailable' : '0%');
            assert.ok(result.html.includes(value == null ? 'Unavailable' : '0%'));
            assert.ok(!result.html.includes('undefined kWh'));
        }
    });
}
