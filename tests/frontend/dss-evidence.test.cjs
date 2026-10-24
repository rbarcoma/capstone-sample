// Render existing DSS pages with qualified API fixtures; never invoke a workflow.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const ts = require('typescript');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const root = path.resolve(__dirname, '../..');
const evidenceModule = { exports: {} };
const evidenceFilename = path.join(root, 'resources/js/lib/dss-evidence.ts');
const evidenceCode = ts.transpileModule(fs.readFileSync(evidenceFilename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
vm.runInThisContext(`(function(require, module, exports) { ${evidenceCode}\n})`, { filename: evidenceFilename })(require, evidenceModule, evidenceModule.exports);

function renderDss(role, row, history = [row]) {
    const states = [], controls = [], nativeButtons = [];
    let index = 0;
    const selectedIndex = role === 'Admin' ? 4 : 5;
    const container = ({ children }) => React.createElement('div', null, children);
    const runtime = require('react/jsx-runtime');
    const jsxAdapter = (original) => (type, props, key) => {
        if (type === 'button') nativeButtons.push(props);
        if (type === 'select' || type === 'input') controls.push({ type, ...props });
        return original(type, props, key);
    };
    const filename = path.join(root, `resources/js/pages/${role}/${role === 'Admin' ? 'DecisionSupport' : 'DecisionSupportResults'}.tsx`);
    const compiled = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
        fileName: filename, compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
    }).outputText;
    const module = { exports: {} };
    const localRequire = (id) => {
        if (id === 'react') return { ...React, useMemo: (fn) => fn(), useState: (initial) => {
            const current = index++;
            states[current] = current === selectedIndex ? row : initial;
            return [states[current], (value) => { states[current] = value; }];
        } };
        if (id === 'react/jsx-runtime') return { ...runtime, jsx: jsxAdapter(runtime.jsx), jsxs: jsxAdapter(runtime.jsxs) };
        if (id === '@inertiajs/react') return { usePage: () => ({ props: { flash: {} } }), router: { post: () => { throw new Error('Must not invoke a workflow'); } } };
        if (id === 'lucide-react') return new Proxy({}, { get: () => () => null });
        if (id === '@/lib/dss-evidence') return evidenceModule.exports;
        if (id.startsWith('@/components/ui/')) return new Proxy({}, { get: () => container });
        throw new Error(`Unexpected dependency: ${id}`);
    };
    vm.runInThisContext(`(function(require, module, exports) { ${compiled}\n})`, { filename })(localRequire, module, module.exports);
    return { html: renderToStaticMarkup(React.createElement(module.exports.default, { latestDss: row, history })), controls, nativeButtons, states };
}

for (const role of ['Admin', 'User']) {
    test(`${role} DSS renders qualified status guidance and existing filters table and assessment dialog without adding controls`, () => {
        const row = { id: 1, forecast_result_id: 2, demand_status: 'Not assessed', readiness_level: 'Not assessed',
            created_at: '2026-10-21T05:55:25Z', forecast_result: { id: 2, year: 2026, month: 9, issuance_label: 'Original recorded issuance' },
            recommendations: ['Insufficient verified basis; indicative resource information only.'],
            priority_actions: ['Verify units and compare issued estimates with observed outcomes.'],
            basis: { rule_version: 'dss_evidence_qualification_v1', reliability_status: 'insufficient_verified_basis',
                inputs: { peak_demand_kw: { value: null, unit: 'kW', availability: 'unavailable' } } } };
        const result = renderDss(role, row);
        assert.ok(result.html.includes('Not assessed'));
        assert.ok(result.html.includes('Insufficient verified basis'));
        assert.ok(result.html.includes('Verify units'));
        assert.ok(result.html.includes('Original recorded issuance'));
        assert.ok(!result.html.includes('High Readiness'));
        assert.ok(!result.html.includes('Historical / unqualified evidence'));
        assert.equal((result.html.match(/<table\b/g) || []).length, 1);
        assert.equal((result.html.match(/<th\b/g) || []).length, 6);
        assert.equal(result.controls.filter(({ type }) => type === 'select').length, 3);
        assert.ok(result.html.includes(role === 'Admin' ? 'Latest Assessment' : 'Latest DSS Assessment'));
        if (role === 'Admin') {
            assert.equal(result.nativeButtons.length, 2);
            for (const button of result.nativeButtons) {
                button.onClick();
                const info = result.states[5];
                assert.ok(info.content.includes('Not assessed'));
                assert.ok(info.content.includes('unverified'));
                assert.ok(!info.content.includes('15% higher'));
                assert.ok(!info.content.includes('High Readiness means'));
            }
        }
    });

    test(`${role} legacy DSS rows and dialogs qualify saved values and advice without changing them`, () => {
        for (const basis of [undefined, null, {}, { rule_version: 'legacy' }, { rule_version: 'unknown_future_version' }]) {
            const row = { id: 1, demand_status: 'High Demand', readiness_level: 'High Readiness',
                created_at: '2030-01-01T00:00:00Z', basis,
                recommendations: ['Original feasibility claim'], priority_actions: ['Original investment action'] };
            const before = JSON.stringify(row);
            const result = renderDss(role, row);
            assert.equal((result.html.match(/Historical \/ unqualified evidence\./g) || []).length, 2);
            assert.ok(result.html.includes('High Demand'));
            assert.ok(result.html.includes('High Readiness'));
            assert.ok(result.html.includes('Original feasibility claim'));
            assert.ok(result.html.includes('Original investment action'));
            assert.ok(!result.html.includes('Not assessed'));
            assert.equal((result.html.match(/<table\b/g) || []).length, 1);
            assert.equal((result.html.match(/<th\b/g) || []).length, 6);
            assert.equal(result.controls.filter(({ type }) => type === 'select').length, 3);
            assert.equal(JSON.stringify(row), before);
        }
    });

    test(`${role} mixed history qualifies each legacy row independently of the selected versioned assessment`, () => {
        const legacy = { id: 1, demand_status: 'Low Demand', readiness_level: 'Moderate Readiness',
            created_at: '2030-01-01T00:00:00Z', recommendations: ['Original saved advice'], priority_actions: [] };
        const current = { id: 2, demand_status: 'Not assessed', readiness_level: 'Not assessed',
            created_at: '2020-01-01T00:00:00Z', recommendations: ['Insufficient verified basis'], priority_actions: [],
            basis: { rule_version: 'dss_evidence_qualification_v1' } };
        const history = [legacy, current];
        const before = JSON.stringify(history);
        const result = renderDss(role, current, history);
        assert.equal((result.html.match(/Historical \/ unqualified evidence\./g) || []).length, 1);
        assert.ok(result.html.includes('Moderate Readiness'));
        assert.ok(result.html.includes('Not assessed'));
        assert.ok(result.html.includes('Insufficient verified basis'));
        const readinessOptions = result.controls.filter(({ type }) => type === 'select')[1].children;
        assert.ok(JSON.stringify(readinessOptions).includes('Moderate Readiness'));
        assert.ok(JSON.stringify(readinessOptions).includes('Not assessed'));
        assert.equal(JSON.stringify(history), before);
    });
}
