<template>
  <div class="accounting-visual" aria-hidden="true">
    <svg viewBox="0 0 480 260" fill="none" focusable="false">
      <!-- El trazo conecta documentos, registro y balance; no representa datos reales. -->
      <path class="flow-track" d="M90 112H124Q148 112 148 140V164H174M306 164H332Q354 164 354 140V112H390" />
      <path class="flow-trace" d="M90 112H124Q148 112 148 140V164H174M306 164H332Q354 164 354 140V112H390" />

      <g class="source-document">
        <path class="paper" d="M28 40H82L104 62V152H28V40Z" />
        <path class="fold" d="M82 40V62H104" />
        <path class="document-rule accent" d="M44 82H72" />
        <path class="document-rule" d="M44 98H86M44 112H78M44 126H62" />
      </g>

      <g class="ledger-book">
        <path class="book-shadow" d="M162 99Q201 85 240 100Q279 85 318 99V208Q279 194 240 209Q201 194 162 208V99Z" />
        <path class="paper" d="M154 89Q197 75 240 90Q283 75 326 89V198Q283 184 240 199Q197 184 154 198V89Z" />
        <path class="book-spine" d="M240 90V199" />
        <path class="book-rule accent" d="M172 111Q196 105 220 111M260 111Q284 105 308 111" />
        <path class="book-rule" d="M172 132Q196 126 220 132M172 148Q196 142 220 148M172 164Q196 158 220 164M260 132Q284 126 308 132M260 148Q284 142 308 148M260 164Q284 158 308 164" />
        <path class="book-binding" d="M234 205H246" />
      </g>

      <g class="balance-document">
        <rect class="paper" x="376" y="40" width="76" height="112" rx="8" />
        <path class="document-rule" d="M392 61H424M392 75H414" />
        <path class="balance-equal" d="M395 100H433M395 114H433" />
      </g>

      <g class="visual-labels">
        <text x="66" y="232" text-anchor="middle">Documentos</text>
        <text x="240" y="242" text-anchor="middle">Libro diario</text>
        <text x="414" y="232" text-anchor="middle">Balance</text>
      </g>
    </svg>
  </div>
</template>

<style scoped>
.accounting-visual {
  width: 100%;
  max-width: 480px;
  margin: 28px auto 24px;
}

svg {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.paper {
  fill: var(--login-art-paper);
  stroke: var(--login-art-line);
  stroke-width: 1.5;
}

.fold, .document-rule, .book-rule, .book-spine {
  stroke: var(--login-art-line);
  stroke-width: 1.5;
}

.accent, .book-binding {
  stroke: var(--login-art-accent);
  stroke-width: 3;
}

.book-shadow {
  fill: var(--login-art-line);
}

.flow-track {
  stroke: var(--login-art-line);
  stroke-width: 1.5;
}

.flow-trace {
  stroke: var(--login-art-accent);
  stroke-width: 2;
  stroke-dasharray: 5 15;
  animation: accounting-flow 1.1s linear 3;
}

.balance-equal {
  stroke: var(--login-art-accent);
  stroke-width: 4;
  animation: accounting-balance 900ms 400ms cubic-bezier(0.16, 1, 0.3, 1) both;
  transform-origin: 414px 107px;
}

.ledger-book {
  animation: accounting-open 800ms cubic-bezier(0.16, 1, 0.3, 1);
  transform-origin: 240px 199px;
}

.visual-labels {
  fill: var(--login-story-muted);
  font-family: var(--font-sans);
  font-size: 12px;
}

@keyframes accounting-flow {
  to { stroke-dashoffset: -40; }
}

@keyframes accounting-open {
  from { transform: translateY(10px) scaleY(0.94); }
  to { transform: translateY(0) scaleY(1); }
}

@keyframes accounting-balance {
  from { transform: scaleX(0.6); opacity: 0.35; }
  to { transform: scaleX(1); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .ledger-book, .flow-trace, .balance-equal {
    animation: none;
  }
}
</style>
