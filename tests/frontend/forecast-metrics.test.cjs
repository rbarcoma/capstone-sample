// Exercise the existing Forecasting component and StatCard with isolated
// Inertia/UI adapters; no browser, backend, ML workflow or saved data involved.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const ts = require('typescript');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const definitions = require('../../resources/forecast-metrics.json');
const root = path.resolve(__dirname, '../..');

function harness() {
    const cards = [];
    const states = [];
    const component = ({ children }) => React.createElement('div', null, children);
    const adapters = new Proxy({}, { get: () => component });
    const hooks = {
        ...React,
        useEffect: () => {},
        useMemo: (callback) => callback(),
        useState: (initial) => {
            const index = states.length;
            states.push(initial);
            return [initial, (value) => { states[index] = value; }];
        },
    };
    function load(relative) {
        const filename = path.join(root, relative);
        const compiled = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
            compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
            fileName: filename,
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
            if (id.startsWith('@/components/ui/') || id === 'lucide-react') return adapters;
            throw new Error(`Unexpected dependency: ${id}`);
        };
        vm.runInThisContext(`(function(require, module, exports) { ${compiled}\n})`, { filename })(localRequire, module, module.exports);
        return module.exports;
    }
    return { Forecasting: load('resources/js/pages/Admin/Forecasting.tsx').default, cards, states };
}

for (const value of [null, undefined, 0, -2]) {
    test(`Forecasting renders ${value} metrics without zero substitution`, () => {
        const { Forecasting, cards, states } = harness();
        const latestForecast = { id: 1, year: 2026, month: 9, mae: value, rmse: value, r2_score: value };
        const html = renderToStaticMarkup(React.createElement(Forecasting, {
            latestForecast, forecastHistory: [latestForecast], metricDefinitions: definitions,
        }));
        for (const title of ['MAE', 'RMSE', 'R2 Score']) {
            assert.equal(cards.find((card) => card.title === title).value, value ?? 'Unavailable');
        }
        assert.ok(html.includes(value == null ? 'Unavailable' : `>${value}<`));
        cards.find((card) => card.title === 'R2 Score').onInfoClick();
        assert.ok(states[0].content.includes(definitions.r2_score));
        assert.ok(states[0].content.includes(definitions.fold_average));
        assert.ok(states[0].content.includes(definitions.legacy));
    });
}

test('Forecasting without any saved forecast renders unavailable evaluation metrics', () => {
    const { Forecasting, cards } = harness();
    renderToStaticMarkup(React.createElement(Forecasting, { forecastHistory: [], metricDefinitions: definitions }));
    for (const title of ['MAE', 'RMSE', 'R2 Score']) {
        assert.equal(cards.find((card) => card.title === title).value, 'Unavailable');
    }
});
