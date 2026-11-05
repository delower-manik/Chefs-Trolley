"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { Can } from "@/components/ui/can";
import { ApiError } from "@/lib/api/client";
import { useTables } from "@/hooks/use-tables";
import { createFloorPlan, deleteFloorPlan, saveFloorPlanLayout, updateFloorPlan, useFloorPlans } from "@/hooks/use-restaurant";
import { clampToPlan, GRID, sizeForShape, snap } from "@/lib/restaurant-utils";
import type { FloorPlan, TableShape } from "@/types/restaurant";

type Placed = { id: string; label: string; capacity: number; x: number; y: number; width: number; height: number; shape: TableShape };

export default function FloorPlansPage() {
  const { items: plans, isLoading, error, refresh } = useFloorPlans();
  const [selectedId, setSelectedId] = useState<string>("");
  const [newName, setNewName] = useState("");
  const [msg, setMsg] = useState<string | null>(null);

  const selected = plans.find((p) => p.id === selectedId) ?? plans[0];

  async function addPlan(e: React.FormEvent) {
    e.preventDefault();
    try {
      const res = await createFloorPlan({ name: newName, width: 800, height: 500, sort_order: plans.length });
      setNewName("");
      await refresh();
      setSelectedId(res.data.id);
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : "Couldn't create the floor plan.");
    }
  }

  return (
    <section className="max-w-6xl mx-auto space-y-5">
      <div>
        <h2 className="text-2xl font-bold text-strong">Floor Plans</h2>
        <p className="text-sm text-text-secondary">
          Drag tables onto the plan to match your dining room. Waiters and the POS see this layout, including which tables have an open tab.
        </p>
      </div>

      <div className="flex flex-wrap gap-2 items-center">
        {plans.map((p) => (
          <button
            key={p.id}
            onClick={() => setSelectedId(p.id)}
            className={`px-3 py-1.5 rounded-full text-sm ${selected?.id === p.id ? "bg-primary text-on-primary" : "bg-surface-hover text-text-secondary"}`}
          >
            {p.name}
          </button>
        ))}
        <Can permission="tables.manage">
          <form onSubmit={addPlan} className="flex gap-2">
            <input required value={newName} onChange={(e) => setNewName(e.target.value)} className="input-field text-sm py-1.5" placeholder="New area (e.g. Rooftop)" maxLength={60} />
            <button className="btn-secondary text-sm">Add</button>
          </form>
        </Can>
      </div>
      {msg && <div className="bg-danger/10 border border-danger/30 text-danger text-sm rounded-lg px-3 py-2">{msg}</div>}

      {error ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Couldn&apos;t load floor plans.</div>
      ) : isLoading ? (
        <div className="h-64 bg-surface-hover rounded-card animate-pulse" />
      ) : !selected ? (
        <div className="card p-10 text-center text-sm text-text-secondary">Create your first floor plan above, then place tables on it.</div>
      ) : (
        <Editor key={selected.id} plan={selected} onSaved={refresh} onDeleted={async () => { setSelectedId(""); await refresh(); }} />
      )}
    </section>
  );
}

function Editor({ plan, onSaved, onDeleted }: { plan: FloorPlan; onSaved: () => Promise<unknown>; onDeleted: () => Promise<void> }) {
  const { tables: allTables, refresh: refreshTables } = useTables();
  const [placed, setPlaced] = useState<Placed[]>(() =>
    plan.tables.map((t) => ({ id: t.id, label: t.label, capacity: t.capacity, x: t.posX, y: t.posY, width: t.width, height: t.height, shape: t.shape }))
  );
  const [selectedTable, setSelectedTable] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [name, setName] = useState(plan.name);
  const canvasRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  const drag = useRef<{ id: string; dx: number; dy: number } | null>(null);

  useEffect(() => {
    const el = canvasRef.current;
    if (!el) return;
    const update = () => setScale(el.clientWidth / plan.width);
    update();
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  }, [plan.width]);

  const placedIds = useMemo(() => new Set(placed.map((p) => p.id)), [placed]);
  const unplaced = allTables.filter((t) => !placedIds.has(t.id));
  const current = placed.find((p) => p.id === selectedTable) ?? null;

  function patch(id: string, change: Partial<Placed>) {
    setPlaced((ps) => ps.map((p) => (p.id === id ? { ...p, ...change } : p)));
    setDirty(true);
  }

  function onPointerDown(e: React.PointerEvent, t: Placed) {
    e.preventDefault();
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    const rect = canvasRef.current!.getBoundingClientRect();
    drag.current = { id: t.id, dx: (e.clientX - rect.left) / scale - t.x, dy: (e.clientY - rect.top) / scale - t.y };
    setSelectedTable(t.id);
  }

  function onPointerMove(e: React.PointerEvent) {
    const d = drag.current;
    if (!d) return;
    const t = placed.find((p) => p.id === d.id);
    if (!t) return;
    const rect = canvasRef.current!.getBoundingClientRect();
    const rawX = snap((e.clientX - rect.left) / scale - d.dx);
    const rawY = snap((e.clientY - rect.top) / scale - d.dy);
    const pos = clampToPlan(rawX, rawY, t, plan);
    if (pos.x !== t.x || pos.y !== t.y) patch(t.id, pos);
  }

  function place(id: string) {
    const t = allTables.find((x) => x.id === id);
    if (!t) return;
    const width = 100;
    const height = 80;
    setPlaced((ps) => [...ps, { id: t.id, label: t.label, capacity: t.capacity, x: 0, y: 0, width, height, shape: "rect" }]);
    setSelectedTable(t.id);
    setDirty(true);
  }

  async function save() {
    setSaving(true);
    setStatus(null);
    try {
      await saveFloorPlanLayout(
        plan.id,
        placed.map((p) => ({ id: p.id, pos_x: p.x, pos_y: p.y, width: p.width, height: p.height, shape: p.shape }))
      );
      if (name !== plan.name) await updateFloorPlan(plan.id, { name });
      setDirty(false);
      setStatus("Layout saved.");
      await Promise.all([onSaved(), refreshTables()]);
    } catch (err) {
      setStatus(err instanceof ApiError ? err.message : "Couldn't save the layout.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid lg:grid-cols-[1fr_260px] gap-4">
      <div className="card p-3 space-y-3">
        <div className="flex items-center justify-between gap-3">
          <input value={name} onChange={(e) => { setName(e.target.value); setDirty(true); }} className="input-field text-sm max-w-xs" aria-label="Floor plan name" />
          <Can permission="tables.manage">
            <div className="flex items-center gap-3">
              {status && <span className="text-xs text-text-secondary">{status}</span>}
              <button
                className="text-xs text-danger"
                onClick={async () => {
                  if (!window.confirm(`Delete ${plan.name}? Its tables stay, but become unplaced.`)) return;
                  try {
                    await deleteFloorPlan(plan.id);
                    await onDeleted();
                  } catch (err) {
                    setStatus(err instanceof ApiError ? err.message : "Couldn't delete.");
                  }
                }}
              >
                Delete plan
              </button>
              <button className="btn-primary text-sm" disabled={!dirty || saving} onClick={save}>
                {saving ? "Saving..." : "Save layout"}
              </button>
            </div>
          </Can>
        </div>

        <div
          ref={canvasRef}
          onPointerMove={onPointerMove}
          onPointerUp={() => (drag.current = null)}
          onPointerLeave={() => (drag.current = null)}
          className="relative w-full bg-background border border-border rounded-lg overflow-hidden touch-none select-none"
          style={{
            aspectRatio: `${plan.width} / ${plan.height}`,
            backgroundImage: "radial-gradient(circle, rgba(255,255,255,0.08) 1px, transparent 1px)",
            backgroundSize: `${GRID * scale}px ${GRID * scale}px`,
          }}
        >
          {placed.map((t) => (
            <div
              key={t.id}
              onPointerDown={(e) => onPointerDown(e, t)}
              className={`absolute flex flex-col items-center justify-center text-strong cursor-grab active:cursor-grabbing border-2 bg-surface ${
                selectedTable === t.id ? "border-primary" : "border-border"
              } ${t.shape === "round" ? "rounded-full" : "rounded-lg"}`}
              style={{ left: t.x * scale, top: t.y * scale, width: t.width * scale, height: t.height * scale }}
            >
              <span className="font-bold text-sm">{t.label}</span>
              <span className="text-[10px] text-text-secondary">{t.capacity} seats</span>
            </div>
          ))}
        </div>
        <p className="text-[11px] text-text-muted">Plan size {plan.width}×{plan.height}. Positions snap to a {GRID}px grid and stay inside the plan.</p>
      </div>

      <div className="space-y-4">
        <div className="card p-3">
          <h3 className="text-sm font-bold text-strong mb-2">Selected table</h3>
          {current ? (
            <div className="space-y-2 text-sm">
              <div className="text-strong font-medium">{current.label}</div>
              <label className="block text-xs text-text-secondary">Shape
                <select
                  value={current.shape}
                  onChange={(e) => {
                    const shape = e.target.value as TableShape;
                    const size = sizeForShape(shape, current.width, current.height);
                    patch(current.id, { shape, ...size, ...clampToPlan(current.x, current.y, size, plan) });
                  }}
                  className="input-field mt-1"
                >
                  <option value="rect">Rectangle</option>
                  <option value="round">Round</option>
                </select>
              </label>
              <div className="grid grid-cols-2 gap-2">
                <label className="block text-xs text-text-secondary">Width
                  <input type="number" min={30} max={600} step={GRID} value={current.width} className="input-field mt-1"
                    onChange={(e) => {
                      const w = Number(e.target.value);
                      const size = sizeForShape(current.shape, w, current.height);
                      patch(current.id, { ...size, ...clampToPlan(current.x, current.y, size, plan) });
                    }} />
                </label>
                <label className="block text-xs text-text-secondary">Height
                  <input type="number" min={30} max={600} step={GRID} value={current.height} className="input-field mt-1"
                    onChange={(e) => {
                      const h = Number(e.target.value);
                      const size = sizeForShape(current.shape, current.width, h);
                      patch(current.id, { ...size, ...clampToPlan(current.x, current.y, size, plan) });
                    }} />
                </label>
              </div>
              <button className="text-xs text-text-secondary" onClick={() => { setPlaced((ps) => ps.filter((p) => p.id !== current.id)); setSelectedTable(null); setDirty(true); }}>
                Remove from this view
              </button>
              <p className="text-[11px] text-text-muted">Removing only hides it here until saved; a table stays assigned to its plan until placed on another.</p>
            </div>
          ) : (
            <p className="text-xs text-text-muted">Click a table to change its shape and size.</p>
          )}
        </div>

        <div className="card p-3">
          <h3 className="text-sm font-bold text-strong mb-2">Not on this plan</h3>
          {unplaced.length === 0 ? (
            <p className="text-xs text-text-muted">Every table is on this plan.</p>
          ) : (
            <ul className="space-y-1.5">
              {unplaced.map((t) => (
                <li key={t.id} className="flex items-center justify-between text-sm">
                  <span className="text-strong">{t.label} <span className="text-xs text-text-secondary">· {t.capacity} seats</span></span>
                  <Can permission="tables.manage">
                    <button className="text-xs text-primary-fg" onClick={() => place(t.id)}>Place</button>
                  </Can>
                </li>
              ))}
            </ul>
          )}
          <p className="text-[11px] text-text-muted mt-2">Placing a table here moves it from any other plan once you save.</p>
        </div>
      </div>
    </div>
  );
}
