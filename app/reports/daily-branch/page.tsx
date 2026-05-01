'use client';

import { useState, useEffect, useCallback } from 'react';
import { useTheme } from '@/contexts/ThemeContext';
import { useAuth } from '@/contexts/AuthContext';
import { useRouter } from 'next/navigation';
import Header from '@/components/Header';
import Sidebar from '@/components/Sidebar';
import storeService, { Store } from '@/services/storeService';
import dailyBranchReportService, { DailyBranchRow } from '@/services/dailyBranchReportService';
import {
  Download, RefreshCw, Calendar, Store as StoreIcon,
  TrendingUp, TrendingDown, Banknote, CreditCard,
  Smartphone, Building2, ShoppingCart, Globe, Users,
  ChevronLeft, ChevronRight,
} from 'lucide-react';

// ─── helpers ──────────────────────────────────────────────────────────────────

function fmt(value: number): string {
  return '৳' + value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function today(): string {
  return new Date().toISOString().split('T')[0];
}

function yesterday(): string {
  const d = new Date();
  d.setDate(d.getDate() - 1);
  return d.toISOString().split('T')[0];
}

function fmtDisplay(dateStr: string): string {
  return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-BD', {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  });
}

// ─── sub-components ──────────────────────────────────────────────────────────

function StatCard({
  label, value, icon: Icon, accent, sub,
}: {
  label: string;
  value: string;
  icon: React.ElementType;
  accent: string;   // tailwind text color class
  sub?: string;
}) {
  return (
    <div className="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">{label}</span>
        <div className={`w-7 h-7 rounded-md flex items-center justify-center bg-gray-50 dark:bg-gray-700 ${accent}`}>
          <Icon className="w-3.5 h-3.5" />
        </div>
      </div>
      <p className={`text-xl font-semibold tracking-tight ${accent}`}>{value}</p>
      {sub && <p className="text-xs text-gray-400 dark:text-gray-500">{sub}</p>}
    </div>
  );
}

function Divider({ label }: { label: string }) {
  return (
    <div className="flex items-center gap-3 mt-4 mb-2">
      <span className="text-[11px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500 whitespace-nowrap">{label}</span>
      <div className="flex-1 h-px bg-gray-100 dark:bg-gray-700" />
    </div>
  );
}

// ─── main page ───────────────────────────────────────────────────────────────

export default function DailyBranchReportPage() {
  const { darkMode, setDarkMode } = useTheme();
  const { role, storeId: userStoreId, isAdmin, isSuperAdmin } = useAuth() as any;
  const router = useRouter();

  const [sidebarOpen, setSidebarOpen]   = useState(false);
  const [stores, setStores]             = useState<Store[]>([]);
  const [selectedStore, setSelectedStore] = useState<number | ''>('');
  const [dateFrom, setDateFrom]         = useState(yesterday());
  const [dateTo, setDateTo]             = useState(yesterday());
  const [rows, setRows]                 = useState<DailyBranchRow[]>([]);
  const [loading, setLoading]           = useState(false);
  const [error, setError]               = useState<string | null>(null);

  // Which date is currently shown in the detail cards (when multi-day range)
  const [activeDateIdx, setActiveDateIdx] = useState(0);

  const canSelectStore = isAdmin || isSuperAdmin;

  // ── boot ──

  useEffect(() => {
    if (!canSelectStore && userStoreId) {
      setSelectedStore(userStoreId);
    }
  }, [canSelectStore, userStoreId]);

  useEffect(() => {
    if (canSelectStore) {
      storeService.getAllStores().then(setStores).catch(() => {});
    }
  }, [canSelectStore]);

  // ── fetch ──

  const fetchReport = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await dailyBranchReportService.getReport({
        from:     dateFrom,
        to:       dateTo,
        store_id: selectedStore || undefined,
      });
      setRows(res.data);
      setActiveDateIdx(0);
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Failed to load report. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [dateFrom, dateTo, selectedStore]);

  useEffect(() => { fetchReport(); }, [fetchReport]);

  // ── derived data ──

  // Unique dates in the result, sorted ascending
  const dates = [...new Set(rows.map(r => r.date))].sort();

  // Rows for the currently visible date
  const activeDate   = dates[activeDateIdx] ?? dateFrom;
  const activeRows   = rows.filter(r => r.date === activeDate);

  // Aggregate across all branches for the active date
  const agg = activeRows.reduce(
    (acc, r) => ({
      pos_sales:             acc.pos_sales             + r.pos_sales,
      online_sales:          acc.online_sales          + r.online_sales,
      social_commerce_sales: acc.social_commerce_sales + r.social_commerce_sales,
      total_sales:           acc.total_sales           + r.total_sales,
      cash_in:               acc.cash_in               + r.cash_in,
      card_in:               acc.card_in               + r.card_in,
      mfs_in:                acc.mfs_in                + r.mfs_in,
      bank_in:               acc.bank_in               + r.bank_in,
      total_money_in:        acc.total_money_in        + r.total_money_in,
      daily_expenses:        acc.daily_expenses        + r.daily_expenses,
      net_cash_position:     acc.net_cash_position     + r.net_cash_position,
    }),
    {
      pos_sales: 0, online_sales: 0, social_commerce_sales: 0, total_sales: 0,
      cash_in: 0, card_in: 0, mfs_in: 0, bank_in: 0, total_money_in: 0,
      daily_expenses: 0, net_cash_position: 0,
    }
  );

  // ── download ──

  function handleDownload() {
    const url = dailyBranchReportService.downloadUrl({
      from:     dateFrom,
      to:       dateTo,
      store_id: selectedStore || undefined,
      combined: true,
    });
    // Open in same tab — browser will treat CSV/zip as download
    const a = document.createElement('a');
    a.href = url;
    // Pass auth token via header isn't possible with <a href>, so rely on cookie/session
    // If your app uses Bearer tokens, generate a signed URL on the backend instead.
    a.click();
  }

  // ── quick-jump: set range to a single day ──

  function jumpTo(offset: number) {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    const s = d.toISOString().split('T')[0];
    setDateFrom(s);
    setDateTo(s);
  }

  const netPositive = agg.net_cash_position >= 0;

  // ── render ───────────────────────────────────────────────────────────────

  return (
    <div className={darkMode ? 'dark' : ''}>
      <div className="flex h-screen bg-white dark:bg-black overflow-hidden">
        <Sidebar isOpen={sidebarOpen} setIsOpen={setSidebarOpen} />

        <div className="flex-1 flex flex-col overflow-hidden">
          <Header darkMode={darkMode} setDarkMode={setDarkMode} toggleSidebar={() => setSidebarOpen(!sidebarOpen)} />

          <main className="flex-1 overflow-y-auto">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 py-5">

              {/* ── Page header ── */}
              <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-5">
                <div>
                  <h1 className="text-xl font-semibold text-gray-900 dark:text-white tracking-tight">
                    Daily Branch Report
                  </h1>
                  <p className="text-gray-500 dark:text-gray-400 text-xs mt-0.5">
                    Sales, collections and expenses per branch per day
                  </p>
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                  {/* Quick-jump buttons */}
                  <button onClick={() => jumpTo(-1)} className="px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Yesterday</button>
                  <button onClick={() => { setDateFrom(today()); setDateTo(today()); }} className="px-2.5 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Today</button>

                  <button
                    onClick={fetchReport}
                    disabled={loading}
                    className="flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 dark:border-gray-700 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors disabled:opacity-50"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
                    Refresh
                  </button>

                  <button
                    onClick={handleDownload}
                    className="flex items-center gap-1.5 px-3 py-1.5 text-sm bg-black dark:bg-white text-white dark:text-black rounded-md hover:bg-gray-800 dark:hover:bg-gray-100 transition-colors font-medium"
                  >
                    <Download className="w-3.5 h-3.5" />
                    Download CSV
                  </button>
                </div>
              </div>

              {/* ── Filters ── */}
              <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-5 flex flex-wrap gap-3 items-end">
                <div className="flex flex-col gap-1 min-w-[130px]">
                  <label className="text-xs font-medium text-gray-500 dark:text-gray-400">From</label>
                  <div className="relative">
                    <Calendar className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                    <input
                      type="date"
                      value={dateFrom}
                      max={dateTo}
                      onChange={e => setDateFrom(e.target.value)}
                      className="pl-8 pr-2 py-1.5 text-sm border border-gray-200 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white w-full"
                    />
                  </div>
                </div>

                <div className="flex flex-col gap-1 min-w-[130px]">
                  <label className="text-xs font-medium text-gray-500 dark:text-gray-400">To</label>
                  <div className="relative">
                    <Calendar className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                    <input
                      type="date"
                      value={dateTo}
                      min={dateFrom}
                      max={today()}
                      onChange={e => setDateTo(e.target.value)}
                      className="pl-8 pr-2 py-1.5 text-sm border border-gray-200 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white w-full"
                    />
                  </div>
                </div>

                {canSelectStore && (
                  <div className="flex flex-col gap-1 min-w-[180px]">
                    <label className="text-xs font-medium text-gray-500 dark:text-gray-400">Branch</label>
                    <div className="relative">
                      <StoreIcon className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                      <select
                        value={selectedStore}
                        onChange={e => setSelectedStore(e.target.value === '' ? '' : Number(e.target.value))}
                        className="pl-8 pr-2 py-1.5 text-sm border border-gray-200 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white w-full appearance-none"
                      >
                        <option value="">All branches</option>
                        {stores.map(s => (
                          <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                      </select>
                    </div>
                  </div>
                )}
              </div>

              {/* ── Error ── */}
              {error && (
                <div className="mb-5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                  <p className="text-sm text-red-700 dark:text-red-400">{error}</p>
                </div>
              )}

              {/* ── Loading skeleton ── */}
              {loading && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-5 animate-pulse">
                  {Array.from({ length: 8 }).map((_, i) => (
                    <div key={i} className="h-24 bg-gray-100 dark:bg-gray-800 rounded-lg" />
                  ))}
                </div>
              )}

              {/* ── Main content ── */}
              {!loading && rows.length > 0 && (
                <>
                  {/* Date navigator (only shown when range > 1 day) */}
                  {dates.length > 1 && (
                    <div className="flex items-center gap-3 mb-4">
                      <button
                        onClick={() => setActiveDateIdx(i => Math.max(0, i - 1))}
                        disabled={activeDateIdx === 0}
                        className="w-7 h-7 flex items-center justify-center rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-30 transition-colors"
                      >
                        <ChevronLeft className="w-4 h-4" />
                      </button>

                      <div className="flex gap-1.5 overflow-x-auto pb-1 flex-1">
                        {dates.map((d, i) => (
                          <button
                            key={d}
                            onClick={() => setActiveDateIdx(i)}
                            className={`flex-shrink-0 px-3 py-1 text-xs rounded-full border transition-colors ${
                              i === activeDateIdx
                                ? 'bg-black dark:bg-white text-white dark:text-black border-transparent font-medium'
                                : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'
                            }`}
                          >
                            {new Date(d + 'T00:00:00').toLocaleDateString('en-BD', { day: 'numeric', month: 'short' })}
                          </button>
                        ))}
                      </div>

                      <button
                        onClick={() => setActiveDateIdx(i => Math.min(dates.length - 1, i + 1))}
                        disabled={activeDateIdx === dates.length - 1}
                        className="w-7 h-7 flex items-center justify-center rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-30 transition-colors"
                      >
                        <ChevronRight className="w-4 h-4" />
                      </button>
                    </div>
                  )}

                  {/* Date heading */}
                  <div className="flex items-baseline justify-between mb-3">
                    <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200">
                      {fmtDisplay(activeDate)}
                    </h2>
                    {activeRows.length > 1 && (
                      <span className="text-xs text-gray-400 dark:text-gray-500">
                        {activeRows.length} branches · totals shown
                      </span>
                    )}
                  </div>

                  {/* ── Summary cards (aggregate across all visible branches) ── */}
                  <Divider label="Sales" />
                  <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-1">
                    <StatCard label="POS Sales"        value={fmt(agg.pos_sales)}             icon={ShoppingCart} accent="text-gray-900 dark:text-white" />
                    <StatCard label="Online Sales"     value={fmt(agg.online_sales)}           icon={Globe}        accent="text-blue-600 dark:text-blue-400" />
                    <StatCard label="Social Commerce"  value={fmt(agg.social_commerce_sales)}  icon={Users}        accent="text-violet-600 dark:text-violet-400" />
                    <StatCard
                      label="Total Sales"
                      value={fmt(agg.total_sales)}
                      icon={TrendingUp}
                      accent="text-emerald-600 dark:text-emerald-400"
                      sub="all channels combined"
                    />
                  </div>

                  <Divider label="Money collected" />
                  <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-1">
                    <StatCard label="Cash In"         value={fmt(agg.cash_in)}        icon={Banknote}   accent="text-gray-900 dark:text-white" />
                    <StatCard label="Card In"         value={fmt(agg.card_in)}         icon={CreditCard} accent="text-blue-600 dark:text-blue-400" />
                    <StatCard label="MFS In"          value={fmt(agg.mfs_in)}          icon={Smartphone} accent="text-pink-600 dark:text-pink-400"   sub="bKash, Nagad, etc." />
                    <StatCard label="Bank Transfer In" value={fmt(agg.bank_in)}        icon={Building2}  accent="text-indigo-600 dark:text-indigo-400" />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-3 mb-1 max-w-md">
                    <StatCard
                      label="Total Money In"
                      value={fmt(agg.total_money_in)}
                      icon={TrendingUp}
                      accent="text-emerald-600 dark:text-emerald-400"
                      sub="cash + card + MFS + bank"
                    />
                  </div>

                  <Divider label="Cost & position" />
                  <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-1">
                    <StatCard
                      label="Daily Expenses"
                      value={fmt(agg.daily_expenses)}
                      icon={TrendingDown}
                      accent="text-red-600 dark:text-red-400"
                      sub="rent, salary, utilities"
                    />
                    <StatCard
                      label="Net Cash Position"
                      value={fmt(agg.net_cash_position)}
                      icon={netPositive ? TrendingUp : TrendingDown}
                      accent={netPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}
                      sub={netPositive ? 'money in > expenses' : 'expenses exceed collections'}
                    />
                  </div>

                  {/* ── Per-branch breakdown (only when multiple branches) ── */}
                  {activeRows.length > 1 && (
                    <>
                      <Divider label="Per branch" />
                      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {activeRows.map((row, idx) => {
                          const branchNet = row.net_cash_position;
                          const branchPos = branchNet >= 0;
                          return (
                            <div
                              key={idx}
                              className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4"
                            >
                              <div className="flex items-center justify-between mb-3">
                                <span className="text-sm font-semibold text-gray-900 dark:text-white">{row.branch}</span>
                                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
                                  branchPos
                                    ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'
                                    : 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400'
                                }`}>
                                  {branchPos ? '+' : ''}{fmt(branchNet)}
                                </span>
                              </div>

                              <div className="space-y-1.5">
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">POS</span>
                                  <span className="text-gray-800 dark:text-gray-200 font-medium">{fmt(row.pos_sales)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Online</span>
                                  <span className="text-gray-800 dark:text-gray-200 font-medium">{fmt(row.online_sales)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Social</span>
                                  <span className="text-gray-800 dark:text-gray-200 font-medium">{fmt(row.social_commerce_sales)}</span>
                                </div>
                                <div className="h-px bg-gray-100 dark:bg-gray-700 my-1" />
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Total sales</span>
                                  <span className="text-gray-900 dark:text-white font-semibold">{fmt(row.total_sales)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Cash in</span>
                                  <span className="text-gray-800 dark:text-gray-200">{fmt(row.cash_in)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Card in</span>
                                  <span className="text-gray-800 dark:text-gray-200">{fmt(row.card_in)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">MFS in</span>
                                  <span className="text-gray-800 dark:text-gray-200">{fmt(row.mfs_in)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Bank in</span>
                                  <span className="text-gray-800 dark:text-gray-200">{fmt(row.bank_in)}</span>
                                </div>
                                <div className="h-px bg-gray-100 dark:bg-gray-700 my-1" />
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Total money in</span>
                                  <span className="text-gray-900 dark:text-white font-semibold">{fmt(row.total_money_in)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                  <span className="text-gray-500 dark:text-gray-400">Expenses</span>
                                  <span className="text-red-600 dark:text-red-400 font-medium">{fmt(row.daily_expenses)}</span>
                                </div>
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </>
                  )}
                </>
              )}

              {/* ── Empty state ── */}
              {!loading && !error && rows.length === 0 && (
                <div className="flex flex-col items-center justify-center py-20 text-center">
                  <div className="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-3">
                    <TrendingUp className="w-5 h-5 text-gray-400" />
                  </div>
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">No data for this period</p>
                  <p className="text-xs text-gray-400 dark:text-gray-500">
                    Try a different date range or check that orders exist for the selected branch.
                  </p>
                </div>
              )}

            </div>
          </main>
        </div>
      </div>
    </div>
  );
}
