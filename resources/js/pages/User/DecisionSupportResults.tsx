import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { legacyDssNotice } from '@/lib/dss-evidence';
import type { DssResult } from '@/types';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

export default function DecisionSupportResults({
    latestDss,
    history = [],
}: {
    latestDss?: DssResult;
    history: DssResult[];
}) {
    const [search, setSearch] = useState('');
    const [monthFilter, setMonthFilter] = useState('all');
    const [readinessFilter, setReadinessFilter] = useState('all');
    const [itemsPerPage, setItemsPerPage] = useState(10);
    const [currentPage, setCurrentPage] = useState(1);
    const [selectedDss, setSelectedDss] = useState<DssResult | null>(null);
    const latestDssId = latestDss?.id;

    const formatPeriod = (item?: DssResult) => {
        if (!item?.forecast_result) {
            return '-';
        }

        return `${item.forecast_result.year}-${String(item.forecast_result.month).padStart(2, '0')}`;
    };

    const filteredHistory = useMemo(() => {
        return history.filter((item) => {
            const period = formatPeriod(item).toLowerCase();
            const forecastMonth = item.forecast_result?.month;
            const monthName = forecastMonth
                ? monthNames[forecastMonth - 1]?.toLowerCase() ?? ''
                : '';
            const demand = item.demand_status?.toLowerCase() ?? '';
            const readiness = item.readiness_level?.toLowerCase() ?? '';
            const generatedBy = item.user?.name?.toLowerCase() ?? 'admin';
            const generated = new Date(
                item.updated_at ?? item.created_at,
            ).toLocaleString().toLowerCase();

            const matchesSearch =
                period.includes(search.toLowerCase()) ||
                monthName.includes(search.toLowerCase()) ||
                demand.includes(search.toLowerCase()) ||
                readiness.includes(search.toLowerCase()) ||
                generatedBy.includes(search.toLowerCase()) ||
                generated.includes(search.toLowerCase());

            const matchesMonth =
                monthFilter === 'all' || forecastMonth === Number(monthFilter);

            const matchesReadiness =
                readinessFilter === 'all' || item.readiness_level === readinessFilter;

            return matchesSearch && matchesMonth && matchesReadiness;
        });
    }, [history, search, monthFilter, readinessFilter]);

    const uniqueReadinessLevels = Array.from(
        new Set(history.map((item) => item.readiness_level).filter(Boolean)),
    );

    const totalPages = Math.ceil(filteredHistory.length / itemsPerPage);

    const paginatedHistory = filteredHistory.slice(
        (currentPage - 1) * itemsPerPage,
        currentPage * itemsPerPage,
    );

    return (
        <div className="space-y-6 p-6">
            <div>
                <h1 className="text-2xl font-semibold">Decision Support Results</h1>
                <p className="text-sm text-muted-foreground">
                    View-only demand status, readiness assessment, recommendations, and priority actions.
                </p>
            </div>

            <Card className="rounded-lg">
                <CardHeader>
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <CardTitle>DSS History Table</CardTitle>

                        <div className="flex flex-col gap-2 md:flex-row">
                            <div className="relative">
                                <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                                <Input
                                    className="w-full pl-9 md:w-64"
                                    placeholder="Search DSS history..."
                                    value={search}
                                    onChange={(event) => {
                                        setSearch(event.target.value);
                                        setCurrentPage(1);
                                    }}
                                />
                            </div>

                            <select
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={monthFilter}
                                onChange={(event) => {
                                    setMonthFilter(event.target.value);
                                    setCurrentPage(1);
                                }}
                            >
                                <option value="all">All Months</option>
                                {monthNames.map((month, index) => (
                                    <option key={month} value={index + 1}>
                                        {month}
                                    </option>
                                ))}
                            </select>

                            <select
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={readinessFilter}
                                onChange={(event) => {
                                    setReadinessFilter(event.target.value);
                                    setCurrentPage(1);
                                }}
                            >
                                <option value="all">All Readiness</option>
                                {uniqueReadinessLevels.map((level) => (
                                    <option key={level} value={level}>
                                        {level}
                                    </option>
                                ))}
                            </select>

                            <select
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={itemsPerPage}
                                onChange={(event) => {
                                    setItemsPerPage(Number(event.target.value));
                                    setCurrentPage(1);
                                }}
                            >
                                <option value={10}>10</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                            </select>
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/40 text-left">
                                <th className="px-3 py-3 font-semibold">Period</th>
                                <th className="px-3 py-3 font-semibold">Demand Status</th>
                                <th className="px-3 py-3 font-semibold">Readiness Level</th>
                                <th className="px-3 py-3 font-semibold">Generated By</th>
                                <th className="px-3 py-3 font-semibold">Assessment Generated</th>
                                <th className="px-3 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            {paginatedHistory.length > 0 ? (
                                paginatedHistory.map((item) => {
                                    const isLatest = item.id === latestDssId;

                                    return (
                                        <tr
                                            key={item.id}
                                            className={`border-b ${isLatest ? 'bg-primary/5 font-bold' : ''}`}
                                        >
                                            <td className="px-3 py-2">
                                                {formatPeriod(item)}
                                                <p className="text-xs text-muted-foreground">
                                                    {legacyDssNotice(item)}{item.forecast_result?.issuance_label}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">{item.demand_status}</td>
                                            <td className="px-3 py-2">{item.readiness_level}</td>
                                            <td className="px-3 py-2">
                                                {item.user?.name ?? 'Admin'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {new Date(item.updated_at ?? item.created_at).toLocaleString()}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex justify-end">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setSelectedDss(item)}
                                                    >
                                                        View
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        No DSS history found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>

                    <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {paginatedHistory.length} of {filteredHistory.length} DSS results
                        </p>

                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={currentPage === 1}
                                onClick={() => setCurrentPage(currentPage - 1)}
                            >
                                Previous
                            </Button>

                            <span className="text-sm">
                                Page {currentPage} of {totalPages || 1}
                            </span>

                            <Button
                                variant="outline"
                                size="sm"
                                disabled={currentPage === totalPages || totalPages === 0}
                                onClick={() => setCurrentPage(currentPage + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Dialog open={!!selectedDss} onOpenChange={() => setSelectedDss(null)}>
                <DialogContent className="max-w-6xl">
                    <DialogHeader>
                        <DialogTitle>Latest DSS Assessment</DialogTitle>
                        <p className="text-sm text-muted-foreground">
                            {legacyDssNotice(selectedDss)}{selectedDss?.forecast_result?.issuance_label} Assessment generated:{' '}
                            {selectedDss ? new Date(selectedDss.updated_at ?? selectedDss.created_at).toLocaleString() : 'unavailable'}.
                        </p>
                    </DialogHeader>

                    {selectedDss && (
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-sm text-muted-foreground">Demand Status</p>
                                <p className="text-xl font-semibold">{selectedDss.demand_status}</p>
                            </div>

                            <div>
                                <p className="text-sm text-muted-foreground">Readiness Level</p>
                                <p className="text-xl font-semibold">{selectedDss.readiness_level}</p>
                            </div>

                            <div>
                                <p className="font-medium">Recommendations</p>
                                <ul className="mt-2 list-disc space-y-2 pl-5 text-sm leading-relaxed">
                                    {selectedDss.recommendations?.length ? (
                                        selectedDss.recommendations.map((item) => (
                                            <li key={item}>{item}</li>
                                        ))
                                    ) : (
                                        <li className="text-muted-foreground">No recommendations yet.</li>
                                    )}
                                </ul>
                            </div>

                            <div>
                                <p className="font-medium">Priority Actions</p>
                                <ul className="mt-2 list-disc space-y-2 pl-5 text-sm leading-relaxed">
                                    {selectedDss.priority_actions?.length ? (
                                        selectedDss.priority_actions.map((item) => (
                                            <li key={item}>{item}</li>
                                        ))
                                    ) : (
                                        <li className="text-muted-foreground">No priority actions yet.</li>
                                    )}
                                </ul>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
