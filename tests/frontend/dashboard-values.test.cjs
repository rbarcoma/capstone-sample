// Render real Dashboard/StatCard components with isolated UI/chart adapters.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const ts = require('typescript');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const root = path.resolve(__dirname, '../..');

function renderDashboard(role, props) {
    const cards = [], charts = [], buttons = [], icons = new Map();
    const container = ({ children }) => React.createElement('div', null, children);
    function load(relative) {
        const filename = path.join(root, relative);
        const compiled = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
            fileName: filename, compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
        }).outputText;
        const module = { exports: {} };
        const localRequire = (id) => {
            if (id === 'react') return { ...React, useState: (value) => [value, () => {}] };
            if (id === 'react/jsx-runtime') return require(id);
            if (id === '@/lib/display-value') return load('resources/js/lib/display-value.ts');
            if (id === '@/lib/dss-evidence') return load('resources/js/lib/dss-evidence.ts');
            if (id === '@/components/dss/stat-card') {
                const { StatCard } = load('resources/js/components/dss/stat-card.tsx');
                return { StatCard: (props) => { cards.push(props); return React.createElement(StatCard, props); } };
            }
            if (id === 'lucide-react') return new Proxy({}, { get: (_, name) => {
                if (!icons.has(name)) {
                    const icon = () => null;
                    icon.displayName = name;
                    icons.set(name, icon);
                }
                return icons.get(name);
            } });
            if (id === 'recharts') return new Proxy({}, { get: (_, component) => (props) => {
                charts.push({ component, ...props });
                return component === 'AreaChart' || component === 'BarChart'
                    ? React.createElement('svg', null, props.children) : React.createElement(container, props);
            } });
            if (id.startsWith('@/components/ui/')) return new Proxy({}, { get: (_, name) => (props) => {
                if (name === 'Button') buttons.push(props);
                return React.createElement(container, props);
            } });
            throw new Error(`Unexpected dependency: ${id}`);
        };
        vm.runInThisContext(`(function(require, module, exports) { ${compiled}\n})`, { filename })(localRequire, module, module.exports);
        return module.exports;
    }
    const Dashboard = load(`resources/js/pages/${role}/Dashboard.tsx`).default;
    return { html: renderToStaticMarkup(React.createElement(Dashboard, props)), cards, charts, buttons };
}

const quantityFields = ['latest_average_consumption', 'latest_predicted_consumption',
    'forecast_change_percent', 'previous_consumption', 'peak_demand_kw', 'solar_irradiance'];

for (const role of ['Admin', 'User']) {
    const historyKey = role === 'Admin' ? 'recentForecasts' : 'forecastHistory';
    test(`${role} Dashboard qualifies legacy DSS summary and advice while preserving versioned and absent output`, () => {
        for (const evidence of [{ basis: null }, { basis: {} }, { basis: { rule_version: 'dss_evidence_qualification_v1' } }, null]) {
            const legacy = evidence && evidence.basis?.rule_version !== 'dss_evidence_qualification_v1';
            const props = { stats: { dss_evidence: evidence,
                demand_status: legacy ? 'High Demand' : evidence ? 'Not assessed' : 'No DSS result',
                readiness_level: legacy ? 'High Readiness' : evidence ? 'Not assessed' : 'No assessment' },
                recommendations: ['Original saved advice'], priorityActions: ['Original saved action'] };
            const before = JSON.stringify(props);
            const result = renderDashboard(role, props);
            assert.equal(result.html.includes('Historical / unqualified evidence.'), Boolean(legacy));
            assert.ok(result.html.includes(props.stats.demand_status));
            assert.ok(result.html.includes(props.stats.readiness_level));
            if (role === 'User') {
                assert.ok(result.html.includes('Original saved advice'));
                assert.ok(result.html.includes('Original saved action'));
                assert.equal((result.html.match(/Historical \/ unqualified evidence\./g) || []).length, legacy ? 3 : 0);
            }
            assert.equal(result.cards.length, role === 'Admin' ? 8 : 4);
            assert.equal(result.buttons.length, role === 'Admin' ? 0 : 4);
            assert.equal(JSON.stringify(props), before);
        }
    });
    for (const value of [null, undefined, 0]) {
        test(`${role} Dashboard renders ${value} quantities distinctly from genuine zero without changing cards controls or icons`, () => {
            const stats = Object.fromEntries(quantityFields.map((key) => [key, value]));
            Object.assign(stats, { datasets: 0, processed_records: 0, forecasts: 0, reports: 0,
                latest_period: '2026-08', forecast_period: '2026-09', forecast_issuance: 'Recorded issuance' });
            const result = renderDashboard(role, { stats,
                [historyKey]: [{ id: 1, year: 2026, month: 9, predicted_consumption_kwh: value }] });
            const expected = value == null ? Array(4).fill('Unavailable')
                : ['0 kWh', '0 kWh', '+0%', role === 'Admin' ? '0 kW' : '0'];
            assert.deepEqual(result.cards.slice(0, 4).map(({ value }) => value), expected);
            assert.deepEqual(result.cards.slice(0, 4).map(({ icon }) => icon.displayName),
                ['Activity', 'Brain', 'ArrowUpRight', role === 'Admin' ? 'BarChart3' : 'Sun']);
            assert.equal(result.cards.length, role === 'Admin' ? 8 : 4);
            assert.equal(result.buttons.length, role === 'Admin' ? 0 : 4);
            assert.equal(result.charts.filter(({ component }) => component === 'AreaChart').length, role === 'Admin' ? 2 : 1);
            assert.equal(result.charts.filter(({ component }) => component === 'BarChart').length, 1);
            assert.ok(result.html.includes('Recorded issuance'));
            assert.ok(!/Unavailable(?: kWh| kW|%)|\+Unavailable|NaN|undefined kWh/.test(result.html));
            if (value == null) assert.equal((result.html.match(/Unavailable/g) || []).length, 7);
            else {
                assert.ok(!result.html.includes('Unavailable'));
                assert.ok(result.html.includes('0 kWh'));
            }
            if (role === 'Admin') assert.deepEqual(result.cards.slice(4).map(({ value }) => value), [0, '0', 0, 0]);
        });
    }

    test(`${role} Dashboard preserves signed change arrows numeric formatting issuance and saved forecast strings`, () => {
        for (const [change, expected, icon] of [[0, '+0%', 'ArrowUpRight'], [12.34, '+12.34%', 'ArrowUpRight'], [-12.34, '-12.34%', 'ArrowDownRight']]) {
            const result = renderDashboard(role, { stats: { latest_average_consumption: 1234.56,
                latest_predicted_consumption: 2345.67, previous_consumption: 1234.56,
                peak_demand_kw: 23.45, solar_irradiance: 4.56, forecast_change_percent: change,
                forecast_issuance: 'Original recorded issuance' },
                [historyKey]: [{ id: 1, year: 2026, month: 9, predicted_consumption_kwh: '1234.50' }] });
            assert.equal(result.cards[0].value, '1,234.56 kWh');
            assert.equal(result.cards[1].value, '2,345.67 kWh');
            assert.equal(result.cards[2].value, expected);
            assert.equal(result.cards[2].icon.displayName, icon);
            assert.equal(result.cards[1].helper, 'Original recorded issuance');
            assert.ok(result.html.includes('1,234.5 kWh'));
            assert.ok(result.html.includes('23.45 kW'));
            assert.ok(result.html.includes('4.56'));
        }
    });

    test(`${role} Dashboard tooltip and formatting guards do not invent zero for unavailable payloads`, () => {
        for (const value of [null, undefined, NaN, Infinity]) {
            const result = renderDashboard(role, { stats: Object.fromEntries(quantityFields.map((key) => [key, value])) });
            assert.deepEqual(result.cards.slice(0, 4).map(({ value }) => value), Array(4).fill('Unavailable'));
            const formatter = result.charts.find(({ component }) => component === 'Tooltip').formatter;
            assert.equal(formatter(value)[0], 'Unavailable');
            assert.equal(formatter([value])[0], 'Unavailable');
            assert.equal(formatter(0)[0], '0 kWh');
            assert.equal(formatter('1234.5')[0], '1,234.5 kWh');
        }
    });
}
