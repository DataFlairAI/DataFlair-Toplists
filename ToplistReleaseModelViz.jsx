import { useState } from "react";

const colors = {
  bg: "#0f172a",
  card: "#1e293b",
  cardHover: "#334155",
  border: "#334155",
  borderActive: "#06b6d4",
  cyan: "#06b6d4",
  cyanDim: "#0e7490",
  emerald: "#10b981",
  emeraldDim: "#047857",
  amber: "#f59e0b",
  amberDim: "#b45309",
  rose: "#f43f5e",
  roseDim: "#be123c",
  purple: "#a855f7",
  slate300: "#cbd5e1",
  slate400: "#94a3b8",
  slate500: "#64748b",
  slate600: "#475569",
  slate700: "#334155",
  slate800: "#1e293b",
  white: "#f8fafc",
};

const Badge = ({ children, color = "cyan", size = "sm" }) => {
  const colorMap = {
    cyan: { bg: "#164e63", text: "#06b6d4", border: "#0e7490" },
    emerald: { bg: "#064e3b", text: "#10b981", border: "#047857" },
    amber: { bg: "#78350f", text: "#f59e0b", border: "#b45309" },
    rose: { bg: "#4c0519", text: "#f43f5e", border: "#be123c" },
    purple: { bg: "#3b0764", text: "#a855f7", border: "#7e22ce" },
    slate: { bg: "#1e293b", text: "#94a3b8", border: "#475569" },
  };
  const c = colorMap[color] || colorMap.cyan;
  return (
    <span
      style={{
        display: "inline-flex",
        alignItems: "center",
        padding: size === "xs" ? "1px 6px" : "2px 10px",
        fontSize: size === "xs" ? 10 : 11,
        fontWeight: 600,
        borderRadius: 9999,
        background: c.bg,
        color: c.text,
        border: `1px solid ${c.border}`,
        letterSpacing: "0.02em",
      }}
    >
      {children}
    </span>
  );
};

const LockIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
    <path d="M7 11V7a5 5 0 0110 0v4" />
  </svg>
);

const EditIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#06b6d4" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
  </svg>
);

const OpenIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="10" strokeDasharray="4 4" />
    <path d="M12 8v8M8 12h8" />
  </svg>
);

// ─── Data ────────────────────────────────────────────────────
const toplistData = {
  id: 42,
  name: "Brazil Casino Toplist",
  slug: "brazil-casinos",
  site: "sigma.world",
  geo: "Brazil",
  template: "Casino — MGA + Visa",
  owner: "Sarah M.",
  releases: [
    {
      period: "2026-03",
      label: "March",
      status: "active",
      activatedAt: "2026-03-01",
      items: [
        { pos: 1, brand: "Bet365", slot: "commercial", deal: "Deal #142", locked: true, offer: "100% up to R$500" },
        { pos: 2, brand: "888casino", slot: "commercial", deal: "Deal #155", locked: true, offer: "R$200 no deposit" },
        { pos: 3, brand: "Betano", slot: "editorial", deal: null, locked: false, offer: "50 free spins" },
        { pos: 4, brand: "Sportingbet", slot: "editorial", deal: null, locked: false, offer: "R$150 welcome" },
        { pos: 5, brand: "KTO", slot: "editorial", deal: null, locked: false, offer: "R$300 bonus" },
      ],
    },
    {
      period: "2026-04",
      label: "April",
      status: "draft",
      activatedAt: null,
      items: [
        { pos: 1, brand: "Bet365", slot: "commercial", deal: "Deal #142", locked: true, offer: "100% up to R$500" },
        { pos: 2, brand: "Rivalo", slot: "commercial", deal: "Deal #168", locked: true, offer: "R$400 welcome" },
        { pos: 3, brand: null, slot: "open", deal: null, locked: false, offer: null },
        { pos: 4, brand: "Betano", slot: "editorial", deal: null, locked: false, offer: "50 free spins" },
        { pos: 5, brand: null, slot: "open", deal: null, locked: false, offer: null },
      ],
    },
    {
      period: "2026-05",
      label: "May",
      status: "draft",
      activatedAt: null,
      items: [
        { pos: 1, brand: "Bet365", slot: "commercial", deal: "Deal #142", locked: true, offer: "100% up to R$500" },
        { pos: 2, brand: null, slot: "open", deal: null, locked: false, offer: null },
        { pos: 3, brand: null, slot: "open", deal: null, locked: false, offer: null },
        { pos: 4, brand: null, slot: "open", deal: null, locked: false, offer: null },
        { pos: 5, brand: null, slot: "open", deal: null, locked: false, offer: null },
      ],
    },
  ],
  pages: [
    { url: "/best-brazil-casinos/", title: "Best Brazil Casinos 2026", type: "shortcode", lastSeen: "2026-03-08" },
    { url: "/brazil/casino-reviews/", title: "Casino Reviews — Brazil", type: "gutenberg", lastSeen: "2026-03-08" },
  ],
};

// ─── Sub-components ──────────────────────────────────────────
const ReleasePill = ({ release, active, onClick }) => {
  const statusColor = {
    active: "emerald",
    draft: "slate",
    scheduled: "cyan",
    expired: "rose",
  };
  return (
    <button
      onClick={onClick}
      style={{
        display: "flex",
        alignItems: "center",
        gap: 8,
        padding: "8px 16px",
        borderRadius: 8,
        border: active ? `2px solid ${colors.cyan}` : `1px solid ${colors.border}`,
        background: active ? "#0c4a6e22" : colors.card,
        cursor: "pointer",
        transition: "all 0.15s",
        color: colors.white,
        fontSize: 13,
        fontWeight: active ? 600 : 400,
        fontFamily: "inherit",
      }}
    >
      <span>{release.label}</span>
      <Badge color={statusColor[release.status]} size="xs">
        {release.status}
      </Badge>
    </button>
  );
};

const SlotRow = ({ item }) => {
  const slotStyles = {
    commercial: { icon: <LockIcon />, borderColor: colors.amber, bgColor: "#78350f15" },
    editorial: { icon: <EditIcon />, borderColor: colors.cyan, bgColor: "#164e6315" },
    open: { icon: <OpenIcon />, borderColor: colors.slate600, bgColor: "#47556915" },
  };
  const s = slotStyles[item.slot];
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: "36px 1fr 100px 140px 1fr",
        alignItems: "center",
        gap: 12,
        padding: "10px 14px",
        borderRadius: 8,
        border: `1px solid ${s.borderColor}33`,
        background: s.bgColor,
        fontSize: 13,
        color: colors.slate300,
      }}
    >
      <span style={{ fontWeight: 700, color: colors.white, fontSize: 15, textAlign: "center" }}>
        #{item.pos}
      </span>
      <span style={{ fontWeight: 500, color: item.brand ? colors.white : colors.slate500, fontStyle: item.brand ? "normal" : "italic" }}>
        {item.brand || "— available —"}
      </span>
      <span style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {s.icon}
        <span style={{ fontSize: 11, textTransform: "uppercase", letterSpacing: "0.04em", color: colors.slate400 }}>
          {item.slot}
        </span>
      </span>
      <span style={{ fontSize: 12, color: item.deal ? colors.amber : colors.slate600 }}>
        {item.deal || "—"}
      </span>
      <span style={{ fontSize: 12, color: item.offer ? colors.slate400 : colors.slate600, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
        {item.offer || "—"}
      </span>
    </div>
  );
};

const ReadinessBar = ({ release }) => {
  const flags = [
    { key: "tracking", label: "Tracking", ready: release.items.every((i) => i.slot === "open" || i.offer) },
    { key: "offers", label: "Offers", ready: release.items.filter((i) => i.brand).every((i) => i.offer) },
    { key: "content", label: "Content", ready: release.status === "active" },
    { key: "publish", label: "Ready", ready: release.status === "active" },
  ];
  return (
    <div style={{ display: "flex", gap: 12, marginTop: 8 }}>
      {flags.map((f) => (
        <div key={f.key} style={{ display: "flex", alignItems: "center", gap: 4 }}>
          <div
            style={{
              width: 8,
              height: 8,
              borderRadius: "50%",
              background: f.ready ? colors.emerald : colors.slate600,
            }}
          />
          <span style={{ fontSize: 11, color: f.ready ? colors.emerald : colors.slate500 }}>{f.label}</span>
        </div>
      ))}
    </div>
  );
};

const PageUsage = ({ pages }) => (
  <div style={{ marginTop: 16, padding: 14, borderRadius: 8, border: `1px solid ${colors.border}`, background: colors.card }}>
    <div style={{ fontSize: 12, fontWeight: 600, color: colors.slate400, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 10 }}>
      Where Used on Website
    </div>
    {pages.map((p, i) => (
      <div
        key={i}
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          padding: "6px 0",
          borderTop: i > 0 ? `1px solid ${colors.border}` : "none",
          fontSize: 12,
          color: colors.slate300,
        }}
      >
        <div>
          <span style={{ color: colors.white, fontWeight: 500 }}>{p.title}</span>
          <span style={{ color: colors.slate500, marginLeft: 8 }}>{p.url}</span>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <Badge color={p.type === "shortcode" ? "purple" : "cyan"} size="xs">
            {p.type}
          </Badge>
          <span style={{ color: colors.slate500, fontSize: 11 }}>Seen {p.lastSeen}</span>
        </div>
      </div>
    ))}
  </div>
);

const InventorySummary = ({ release }) => {
  const commercial = release.items.filter((i) => i.slot === "commercial").length;
  const editorial = release.items.filter((i) => i.slot === "editorial").length;
  const open = release.items.filter((i) => i.slot === "open").length;
  const total = release.items.length;
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: "1fr 1fr 1fr 1fr",
        gap: 10,
        padding: 12,
        borderRadius: 8,
        background: colors.card,
        border: `1px solid ${colors.border}`,
      }}
    >
      {[
        { label: "Total", value: total, color: colors.white },
        { label: "Commercial", value: commercial, color: colors.amber },
        { label: "Editorial", value: editorial, color: colors.cyan },
        { label: "Open to Sell", value: open, color: open > 0 ? colors.emerald : colors.slate500 },
      ].map((s) => (
        <div key={s.label} style={{ textAlign: "center" }}>
          <div style={{ fontSize: 22, fontWeight: 700, color: s.color }}>{s.value}</div>
          <div style={{ fontSize: 10, color: colors.slate500, textTransform: "uppercase", letterSpacing: "0.04em" }}>
            {s.label}
          </div>
        </div>
      ))}
    </div>
  );
};

// ─── Screens ─────────────────────────────────────────────────
const ToplistDetailScreen = () => {
  const [activeRelease, setActiveRelease] = useState(0);
  const d = toplistData;
  const release = d.releases[activeRelease];

  return (
    <div>
      {/* Header */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 20 }}>
        <div>
          <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 4 }}>
            <h2 style={{ margin: 0, fontSize: 20, fontWeight: 700, color: colors.white }}>{d.name}</h2>
            <Badge color="emerald">ACTIVE</Badge>
          </div>
          <div style={{ fontSize: 12, color: colors.slate400 }}>
            ID: {d.id} · Slug: <span style={{ color: colors.cyan }}>{d.slug}</span> · {d.site} · {d.geo} · Owner: {d.owner}
          </div>
          <div style={{ fontSize: 11, color: colors.slate500, marginTop: 2 }}>
            Targeting Profile: {d.template}
          </div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button style={{ padding: "7px 14px", borderRadius: 6, border: `1px solid ${colors.border}`, background: colors.card, color: colors.slate300, fontSize: 12, cursor: "pointer", fontFamily: "inherit" }}>
            New Release
          </button>
          <button style={{ padding: "7px 14px", borderRadius: 6, border: "none", background: colors.cyan, color: colors.bg, fontSize: 12, fontWeight: 600, cursor: "pointer", fontFamily: "inherit" }}>
            Activate
          </button>
        </div>
      </div>

      {/* Release timeline */}
      <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
        {d.releases.map((r, i) => (
          <ReleasePill key={r.period} release={r} active={i === activeRelease} onClick={() => setActiveRelease(i)} />
        ))}
      </div>

      {/* Release metadata + readiness */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
        <div style={{ fontSize: 13, color: colors.slate400 }}>
          <span style={{ fontWeight: 600, color: colors.white }}>{release.label} {release.period}</span>
          {release.activatedAt && <span> · Activated {release.activatedAt}</span>}
        </div>
        <ReadinessBar release={release} />
      </div>

      {/* Inventory summary */}
      <InventorySummary release={release} />

      {/* Slot grid */}
      <div style={{ display: "flex", flexDirection: "column", gap: 6, marginTop: 12 }}>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "36px 1fr 100px 140px 1fr",
            gap: 12,
            padding: "4px 14px",
            fontSize: 10,
            color: colors.slate500,
            textTransform: "uppercase",
            letterSpacing: "0.06em",
          }}
        >
          <span style={{ textAlign: "center" }}>Pos</span>
          <span>Brand</span>
          <span>Type</span>
          <span>Deal</span>
          <span>Offer</span>
        </div>
        {release.items.map((item) => (
          <SlotRow key={item.pos} item={item} />
        ))}
      </div>

      {/* Page usage */}
      <PageUsage pages={d.pages} />
    </div>
  );
};

// ─── Data Model Diagram ──────────────────────────────────────
const DataModelDiagram = () => {
  const Entity = ({ x, y, title, fields, color }) => (
    <g>
      <rect x={x} y={y} width={220} height={24 + fields.length * 18} rx={6} fill={colors.card} stroke={color} strokeWidth={1.5} />
      <rect x={x} y={y} width={220} height={24} rx={6} fill={color + "25"} />
      <text x={x + 12} y={y + 16} fontSize={12} fontWeight={700} fill={color} fontFamily="system-ui">
        {title}
      </text>
      {fields.map((f, i) => (
        <text key={i} x={x + 12} y={y + 40 + i * 18} fontSize={11} fill={f.pk ? colors.white : colors.slate400} fontWeight={f.pk ? 600 : 400} fontFamily="system-ui">
          {f.pk ? "🔑 " : f.fk ? "→ " : "   "}{f.name}
          <tspan fill={colors.slate600}> {f.type}</tspan>
        </text>
      ))}
    </g>
  );

  const Arrow = ({ x1, y1, x2, y2, label }) => (
    <g>
      <line x1={x1} y1={y1} x2={x2} y2={y2} stroke={colors.slate600} strokeWidth={1.5} markerEnd="url(#arrowhead)" />
      {label && (
        <text x={(x1 + x2) / 2} y={(y1 + y2) / 2 - 6} fontSize={10} fill={colors.slate500} textAnchor="middle" fontFamily="system-ui">
          {label}
        </text>
      )}
    </g>
  );

  return (
    <div style={{ marginTop: 24, padding: 16, borderRadius: 8, border: `1px solid ${colors.border}`, background: colors.bg }}>
      <div style={{ fontSize: 13, fontWeight: 600, color: colors.slate400, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 12 }}>
        Data Model — Toplist + Release Architecture
      </div>
      <svg width="100%" height="380" viewBox="0 0 740 380">
        <defs>
          <marker id="arrowhead" markerWidth="8" markerHeight="6" refX="8" refY="3" orient="auto">
            <polygon points="0 0, 8 3, 0 6" fill={colors.slate600} />
          </marker>
        </defs>

        <Entity
          x={20} y={20} title="toplists" color={colors.cyan}
          fields={[
            { name: "id", type: "bigint", pk: true },
            { name: "name", type: "string" },
            { name: "slug", type: "string unique" },
            { name: "site_id", type: "FK", fk: true },
            { name: "geo_type + geo_id", type: "" },
            { name: "list_template_id", type: "FK optional", fk: true },
            { name: "active_release_id", type: "FK → releases", fk: true },
            { name: "owner_id", type: "FK", fk: true },
            { name: "status", type: "active|retired" },
          ]}
        />

        <Entity
          x={300} y={20} title="toplist_releases" color={colors.emerald}
          fields={[
            { name: "id", type: "bigint", pk: true },
            { name: "toplist_id", type: "FK → toplists", fk: true },
            { name: "period", type: "string (2026-04)" },
            { name: "status", type: "draft|sched|active|exp" },
            { name: "scheduled_at", type: "timestamp?" },
            { name: "activated_at", type: "timestamp?" },
            { name: "version", type: "YmdHis" },
            { name: "tracking_ready", type: "bool" },
            { name: "offers_ready", type: "bool" },
            { name: "publish_ready", type: "bool" },
          ]}
        />

        <Entity
          x={580} y={20} title="toplist_items" color={colors.amber}
          fields={[
            { name: "id", type: "bigint", pk: true },
            { name: "release_id", type: "FK → releases", fk: true },
            { name: "brand_id", type: "FK → brands", fk: true },
            { name: "position", type: "int" },
            { name: "slot_type", type: "comm|edit|open" },
            { name: "is_locked", type: "bool" },
            { name: "deal_id", type: "FK?", fk: true },
            { name: "offer_id", type: "FK?", fk: true },
            { name: "tracking_link", type: "string?" },
          ]}
        />

        <Arrow x1={240} y1={100} x2={300} y2={100} label="has many" />
        <Arrow x1={520} y1={100} x2={580} y2={100} label="has many" />

        <Entity
          x={20} y={260} title="list_templates (targeting)" color={colors.purple}
          fields={[
            { name: "id", type: "bigint", pk: true },
            { name: "name", type: "string" },
            { name: "product_type_id", type: "FK", fk: true },
            { name: "licenses, payments, games...", type: "pivots" },
          ]}
        />

        <Entity
          x={340} y={280} title="toplist_page_usages" color={colors.rose}
          fields={[
            { name: "toplist_id", type: "FK → toplists", fk: true },
            { name: "page_url", type: "string" },
            { name: "embed_type", type: "shortcode|block" },
            { name: "last_detected_at", type: "timestamp" },
          ]}
        />

        <Arrow x1={140} y1={210} x2={140} y2={260} label="optional FK" />
        <Arrow x1={240} y1={200} x2={340} y2={300} label="has many" />
      </svg>
    </div>
  );
};

// ─── Main App ────────────────────────────────────────────────
export default function ToplistReleaseModelViz() {
  const [view, setView] = useState("ui");

  return (
    <div
      style={{
        fontFamily: "'Inter', system-ui, -apple-system, sans-serif",
        background: colors.bg,
        color: colors.white,
        minHeight: "100vh",
        padding: 24,
      }}
    >
      <div style={{ maxWidth: 820, margin: "0 auto" }}>
        {/* Title */}
        <div style={{ textAlign: "center", marginBottom: 24 }}>
          <h1 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: colors.white }}>
            Toplist Release Model
          </h1>
          <p style={{ margin: "4px 0 0", fontSize: 13, color: colors.slate400 }}>
            Stable identity · Monthly releases · Commercial slot planning
          </p>
        </div>

        {/* Tab switch */}
        <div style={{ display: "flex", justifyContent: "center", gap: 4, marginBottom: 24, background: colors.card, borderRadius: 8, padding: 4, width: "fit-content", margin: "0 auto 24px" }}>
          {[
            { key: "ui", label: "Toplist Detail UX" },
            { key: "model", label: "Data Model" },
          ].map((t) => (
            <button
              key={t.key}
              onClick={() => setView(t.key)}
              style={{
                padding: "6px 16px",
                borderRadius: 6,
                border: "none",
                background: view === t.key ? colors.cyan : "transparent",
                color: view === t.key ? colors.bg : colors.slate400,
                fontSize: 12,
                fontWeight: 600,
                cursor: "pointer",
                fontFamily: "inherit",
              }}
            >
              {t.label}
            </button>
          ))}
        </div>

        {/* Content */}
        {view === "ui" && <ToplistDetailScreen />}
        {view === "model" && <DataModelDiagram />}

        {/* Legend */}
        <div style={{ marginTop: 24, padding: 14, borderRadius: 8, border: `1px solid ${colors.border}`, background: colors.card }}>
          <div style={{ fontSize: 11, fontWeight: 600, color: colors.slate500, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 8 }}>
            Slot Type Legend
          </div>
          <div style={{ display: "flex", gap: 24, fontSize: 12, color: colors.slate400 }}>
            <span style={{ display: "flex", alignItems: "center", gap: 6 }}><LockIcon /> <strong style={{ color: colors.amber }}>Commercial</strong> — Paid, deal-locked, cannot reorder</span>
            <span style={{ display: "flex", alignItems: "center", gap: 6 }}><EditIcon /> <strong style={{ color: colors.cyan }}>Editorial</strong> — Organic, freely editable</span>
            <span style={{ display: "flex", alignItems: "center", gap: 6 }}><OpenIcon /> <strong style={{ color: colors.slate500 }}>Open</strong> — Available to sell</span>
          </div>
        </div>

        {/* Key insight */}
        <div style={{ marginTop: 16, padding: 14, borderRadius: 8, border: `1px solid ${colors.cyanDim}44`, background: "#0c4a6e15", fontSize: 12, color: colors.slate300, lineHeight: 1.6 }}>
          <strong style={{ color: colors.cyan }}>Key insight:</strong> The toplist ID (42) and slug (brazil-casinos) never change.
          WordPress embeds <code style={{ background: colors.slate700, padding: "1px 5px", borderRadius: 3, fontSize: 11 }}>[dataflair_toplist id="42"]</code> survive
          every monthly release. The API always resolves to the active release. Sales sees open slots. Operations publishes with one click.
        </div>
      </div>
    </div>
  );
}
