/* Контроль звонков — SPA. Vue 3 (global build), без сборки. */
const BUILD = '2026-08-06 07:20';   // обновлять при каждой правке app.js — видно в консоли, отличает кеш от свежего файла
console.info('calls-review build', BUILD);

const { createApp, ref, reactive, computed, onMounted, watch } = Vue;

const api = async (url, opts = {}) => {
  const r = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...opts });
  const ct = r.headers.get('content-type') || '';
  const j = await r.json().catch(() => null);
  if (r.status === 401) { app._instance.exposed.setUser(null); throw new Error('UNAUTHORIZED'); }
  if (!r.ok) throw new Error(j?.message || j?.error || ('HTTP ' + r.status));
  // Не-JSON или не объект — обычно PHP-warning перед телом ответа либо отдача html вместо API.
  // Лучше явная ошибка, чем падение шаблона на неожиданной структуре.
  if (!ct.includes('application/json') || typeof j !== 'object' || j === null) throw new Error('BAD_RESPONSE: ' + url);
  return j;
};
const today = () => new Date().toISOString().slice(0, 10);
const daysAgo = n => new Date(Date.now() - n * 864e5).toISOString().slice(0, 10);

const PRESETS = [
  { id: '',           label: 'Все' },
  { id: 'unreviewed', label: 'Непроверенные' },
  { id: 'problem',    label: 'Проблемные' },
  { id: 'low_score',  label: 'Оценка ≤ 3' },
  { id: 'non_target', label: 'Нецелевые' },
  { id: 'changed',    label: 'Изменённые оценки' },
];

const ScoreCell = {
  props: ['value', 'old'],
  template: `
    <span v-if="value !== null && value !== undefined && value !== ''" class="score" :class="'s'+Math.round(value)">
      <span class="dots"><i></i><i></i><i></i><i></i><i></i></span>
      <span class="val">{{ value }}</span>
      <span v-if="old !== undefined && old !== '' && +old !== +value" class="old">{{ old }}</span>
    </span>
    <span v-else class="badge neutral">—</span>`
};

const app = createApp({
  components: { ScoreCell },
  setup() {
    const user = ref(undefined);           // undefined=загрузка, null=не залогинен
    const view = ref('summary');
    const toast = ref('');
    const showToast = (t) => { toast.value = t; setTimeout(() => toast.value = '', 4000); };
    const setUser = (u) => { user.value = u; };

    // ---------- сводка ----------
    const sumDate = ref(today());
    const summary = ref(null);
    const loadSummary = async () => {
      summary.value = null;
      summary.value = await api('api/summary?date=' + sumDate.value);
    };
    watch(sumDate, loadSummary);
    const hasOperators = computed(() => ['operators_sales', 'operators_service', 'operators_other']
      .some(k => (summary.value?.[k] || []).length));

    // ---------- список ----------
    const dicts = ref({ operators: [], groups: [] });
    const f = reactive({ from: today(), to: today(), group: '', operator: '', direction: '', status: '', preset: '', sort: 'call_datetime', dir: 'desc', page: 1 });
    const list = ref(null);
    const loading = ref(false);
    const qs = () => new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))).toString();
    const loadList = async () => {
      loading.value = true;
      try { list.value = await api('api/calls?' + qs()); }
      catch (e) { showToast('Не удалось загрузить список: ' + e.message); }
      loading.value = false;
    };
    watch(f, () => { loadList(); }, { deep: true });
    const setPreset = (p) => { f.preset = p; f.page = 1; };
    const setPeriod = (from, to) => { f.from = from; f.to = to; f.page = 1; };
    const sortBy = (col) => {
      if (f.sort === col) f.dir = f.dir === 'asc' ? 'desc' : 'asc';
      else { f.sort = col; f.dir = 'desc'; }
    };
    const goGroup = (g, date) => { view.value = 'calls'; Object.assign(f, { group: g === 'Все' ? '' : g, operator: '', from: date, to: date, page: 1 }); };
    const goOperator = (op, date) => { view.value = 'calls'; Object.assign(f, { operator: op, group: '', from: date, to: date, page: 1 }); };

    // ---------- выделение и массовое подтверждение ----------
    const selected = reactive(new Set());
    const toggle = (k) => { selected.has(k) ? selected.delete(k) : selected.add(k); };
    const clearSel = () => selected.clear();
    const pageKeys = computed(() => (list.value?.items || []).filter(c => c.reviewable && !c.review_status).map(c => c.call_key));
    const allPageSelected = computed(() => pageKeys.value.length > 0 && pageKeys.value.every(k => selected.has(k)));
    const togglePage = () => {
      if (allPageSelected.value) pageKeys.value.forEach(k => selected.delete(k));
      else pageKeys.value.forEach(k => selected.add(k));
    };
    const selectAllFiltered = async () => {
      try {
        const { keys } = await api('api/calls/keys?' + qs());
        keys.forEach(k => selected.add(k));
        showToast('Выбрано по фильтру: ' + keys.length);
      } catch (e) { showToast('Ошибка: ' + e.message); }
    };
    const bulkBusy = ref(false);
    const confirmSelected = async () => {
      const keys = [...selected];
      if (!keys.length) return;
      if (!confirm('Подтвердить оценки: ' + keys.length + ' звонков?')) return;
      bulkBusy.value = true;
      try {
        const { results } = await api('api/verdicts', {
          method: 'POST',
          body: JSON.stringify({ items: keys.map(k => ({ call_key: k, action: 'CONFIRM' })) }),
        });
        const ok = results.filter(r => r.ok).length;
        const skipped = results.filter(r => r.code === 'ALREADY_REVIEWED').length;
        showToast(`Подтверждено ${ok}` + (skipped ? `, пропущено ${skipped} уже проверенных` : ''));
        clearSel(); await loadList();
      } catch (e) { showToast('Запись не выполнена: ' + e.message); }
      bulkBusy.value = false;
    };

    // ---------- карточка ----------
    const card = ref(null);
    const cardBusy = ref(false);
    const trTab = ref('all');
    const verdict = reactive({ mode: '', score: 5, comment: '', error: '' });
    const openCard = async (key) => {
      card.value = { call_key: key, _loading: true };
      trTab.value = 'all'; Object.assign(verdict, { mode: '', score: 5, comment: '', error: '' });
      try { card.value = await api('api/calls/' + encodeURIComponent(key)); }
      catch (e) { showToast('Карточка: ' + e.message); card.value = null; }
    };
    const closeCard = () => card.value = null;
    const cardIndex = computed(() => (list.value?.items || []).findIndex(c => c.call_key === card.value?.call_key));
    const nav = (d) => {
      const items = list.value?.items || [];
      const i = cardIndex.value + d;
      if (i >= 0 && i < items.length) openCard(items[i].call_key);
    };
    const sendVerdict = async () => {
      verdict.error = '';
      const item = { call_key: card.value.call_key, action: verdict.mode };
      if (verdict.mode === 'CHANGE_SCORE') {
        if (!verdict.comment.trim()) { verdict.error = 'Комментарий обязателен при изменении оценки.'; return; }
        item.new_score = verdict.score;
      }
      if (verdict.comment.trim()) item.comment = verdict.comment.trim();
      cardBusy.value = true;
      try {
        const { results } = await api('api/verdicts', { method: 'POST', body: JSON.stringify({ items: [item] }) });
        const r = results[0];
        if (!r.ok) verdict.error = r.code === 'ALREADY_REVIEWED' ? 'Звонок уже проверен.' : 'Ошибка: ' + r.code;
        else { showToast(verdict.mode === 'CONFIRM' ? 'Оценка подтверждена' : 'Оценка изменена'); await loadList(); await openCard(item.call_key); }
      } catch (e) { verdict.error = 'Запись не выполнена: ' + e.message; }
      cardBusy.value = false;
    };

    const fmtDT = (s) => (s || '').slice(0, 16);
    const dirLabel = (d) => d === 'INBOUND' ? 'Вход.' : d === 'OUTBOUND' ? 'Исх.' : d;

    onMounted(async () => {
      try {
        const me = await api('api/me');
        user.value = me.user;
        if (me.user) {
          dicts.value = await api('api/operators').catch(() => dicts.value);
          await Promise.all([loadSummary(), loadList()]);
        }
      } catch { user.value = null; }
    });

    return { user, setUser, view, toast, PRESETS,
      sumDate, summary, hasOperators, goGroup, goOperator,
      dicts, f, list, loading, setPreset, setPeriod, sortBy, today, daysAgo,
      selected, toggle, togglePage, allPageSelected, selectAllFiltered, clearSel, confirmSelected, bulkBusy,
      card, openCard, closeCard, nav, cardIndex, trTab, verdict, sendVerdict, cardBusy,
      fmtDT, dirLabel };
  },
  template: `
  <div v-if="user === undefined" class="loading">Загрузка…</div>

  <div v-else-if="user === null" class="login">
    <div class="login-card">
      <h1>Контроль звонков</h1>
      <p>Оценки разговоров, проверка и оперативная сводка по группам.</p>
      <a class="btn-primary" style="text-decoration:none;display:inline-block" href="auth/login">Войти через Битрикс24</a>
    </div>
  </div>

  <template v-else>
    <div class="topbar">
      <span class="brand">Контроль звонков <small>ТЕМЕД</small></span>
      <nav class="tabs">
        <button :class="{active:view==='summary'}" @click="view='summary'">Сводка</button>
        <button :class="{active:view==='calls'}" @click="view='calls'">Звонки</button>
      </nav>
      <span class="spacer"></span>
      <span class="user">{{ user.name }} <a href="auth/logout">Выйти</a></span>
    </div>

    <!-- ================= СВОДКА ================= -->
    <div class="page" v-show="view==='summary'">
      <div class="summary-head">
        <h2>День</h2>
        <input type="date" v-model="sumDate">
        <span v-if="summary" class="count-note">звонков всего: <b class="mono">{{ summary.total }}</b></span>
      </div>
      <div v-if="!summary" class="loading">Загрузка сводки…</div>
      <template v-else>
        <div class="group-cards">
          <div class="gcard" v-for="g in (summary.groups || [])" :key="g.group" @click="goGroup(g.group, summary.date)">
            <div class="gname">{{ g.group }}</div>
            <div class="big mono">{{ g.calls }}<small>звонков</small></div>
            <div class="rows">
              <span>Средний балл: <b class="mono">{{ g.avg_score ?? '—' }}</b></span>
              <span>Вход. / исх.: <b class="mono">{{ g.inbound }} / {{ g.outbound }}</b></span>
              <template v-if="g.sales_inbound">
                <span class="subhead">Входящие</span>
                <span>Звонков: <b class="mono">{{ g.sales_inbound.calls }}</b> · уник. клиентов: <b class="mono">{{ g.sales_inbound.unique_clients }}</b></span>
                <span>Целевые / нецелевые: <b class="mono">{{ g.sales_inbound.target_calls }} / {{ g.sales_inbound.non_target }}</b></span>
                <span title="уникальные клиенты: целевой — есть хотя бы один целевой входящий за день; нецелевой — только нецелевые">Уник. целевые / нецелевые: <b class="mono">{{ g.sales_inbound.unique_target }} / {{ g.sales_inbound.unique_non_target }}</b></span>
                <span title="записавшихся / уникальных целевых клиентов">Конверсия по уник. целевым: <b class="mono">{{ g.sales_inbound.conversion ?? '—' }}%</b> ({{ g.sales_inbound.booked }} / {{ g.sales_inbound.target }})</span>
                <span class="subhead">Исходящие</span>
                <span>Звонков: <b class="mono">{{ g.sales_outbound.calls }}</b> · уник. клиентов: <b class="mono">{{ g.sales_outbound.unique_clients }}</b></span>
                <span>Целевые / нецелевые: <b class="mono">{{ g.sales_outbound.target_calls }} / {{ g.sales_outbound.non_target }}</b></span>
                <span title="уникальные клиенты: целевой — есть хотя бы один целевой исходящий за день; нецелевой — только нецелевые">Уник. целевые / нецелевые: <b class="mono">{{ g.sales_outbound.unique_target }} / {{ g.sales_outbound.unique_non_target }}</b></span>
                <span title="записавшихся / уникальных целевых клиентов">Конверсия по уник. целевым: <b class="mono">{{ g.sales_outbound.conversion ?? '—' }}%</b> ({{ g.sales_outbound.booked }} / {{ g.sales_outbound.target }})</span>
              </template>
              <span><span class="badge" :class="g.unreviewed ? 'warn' : 'ok'">непроверенных: {{ g.unreviewed }}</span></span>
            </div>
          </div>
        </div>

        <div class="panel" v-if="!hasOperators">
          <h3>Нагрузка на операторов</h3>
          <div class="empty">За выбранный день звонков нет.</div>
        </div>
        <template v-else>
          <div class="panel" v-if="(summary.operators_sales || []).length">
            <h3>Нагрузка на операторов · Продажи</h3>
            <table>
              <thead><tr>
                <th>Оператор</th><th class="num">Звонков</th><th class="num">Уникальных</th>
                <th class="num">Записей</th><th class="num">Конверсия</th>
                <th class="num">Общее время</th><th class="num">Средний балл</th>
              </tr></thead>
              <tbody>
                <tr v-for="o in (summary.operators_sales || [])" :key="o.operator" style="cursor:pointer" @click="goOperator(o.operator, summary.date)">
                  <td>{{ o.operator }}</td>
                  <td class="num mono">{{ o.calls }}</td>
                  <td class="num mono">{{ o.target }}</td>
                  <td class="num mono">{{ o.booked }}</td>
                  <td class="num mono">{{ o.conversion === null ? '—' : o.conversion + '%' }}</td>
                  <td class="num mono">{{ o.duration }}</td>
                  <td class="num"><score-cell :value="o.avg_score"/></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="panel" v-if="(summary.operators_service || []).length">
            <h3>Нагрузка на операторов · Сервис</h3>
            <table>
              <thead><tr><th>Оператор</th><th class="num">Звонков</th><th class="num">Общее время</th><th class="num">Средний балл</th></tr></thead>
              <tbody>
                <tr v-for="o in (summary.operators_service || [])" :key="o.operator" style="cursor:pointer" @click="goOperator(o.operator, summary.date)">
                  <td>{{ o.operator }}</td>
                  <td class="num mono">{{ o.calls }}</td>
                  <td class="num mono">{{ o.duration }}</td>
                  <td class="num"><score-cell :value="o.avg_score"/></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="panel" v-if="(summary.operators_other || []).length">
            <h3>Нагрузка на операторов · Прочие</h3>
            <table>
              <thead><tr><th>Оператор</th><th>Группа</th><th class="num">Звонков</th><th class="num">Общее время</th><th class="num">Средний балл</th></tr></thead>
              <tbody>
                <tr v-for="o in (summary.operators_other || [])" :key="o.operator" style="cursor:pointer" @click="goOperator(o.operator, summary.date)">
                  <td>{{ o.operator }}</td>
                  <td><span class="badge neutral">{{ o.group }}</span></td>
                  <td class="num mono">{{ o.calls }}</td>
                  <td class="num mono">{{ o.duration }}</td>
                  <td class="num"><score-cell :value="o.avg_score"/></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </template>
    </div>

    <!-- ================= СПИСОК ================= -->
    <div class="page" v-show="view==='calls'">
      <div class="filters">
        <input type="date" v-model="f.from"> — <input type="date" v-model="f.to">
        <button class="btn" @click="setPeriod(today(), today())">Сегодня</button>
        <button class="btn" @click="setPeriod(daysAgo(1), daysAgo(1))">Вчера</button>
        <button class="btn" @click="setPeriod(daysAgo(6), today())">7 дней</button>
        <select v-model="f.group"><option value="">Все группы</option><option v-for="g in dicts.groups" :value="g">{{ g }}</option></select>
        <select v-model="f.operator"><option value="">Все операторы</option><option v-for="o in dicts.operators" :value="o">{{ o }}</option></select>
        <select v-model="f.direction"><option value="">Вход. и исх.</option><option value="INBOUND">Входящие</option><option value="OUTBOUND">Исходящие</option></select>
        <select v-model="f.status"><option value="">Проверка: все</option><option value="unreviewed">Непроверенные</option><option value="reviewed">Проверенные</option></select>
        <span class="count-note" v-if="list">найдено: <b class="mono">{{ list.total }}</b></span>
      </div>
      <div class="presets">
        <button v-for="p in PRESETS" :key="p.id" class="chip" :class="{active:f.preset===p.id}" @click="setPreset(p.id)">{{ p.label }}</button>
        <button class="chip" @click="selectAllFiltered">Выбрать всё по фильтру</button>
      </div>

      <div class="panel">
        <div v-if="loading" class="loading">Загрузка…</div>
        <div v-else-if="!list || !(list.items || []).length" class="empty">По выбранным условиям звонков нет. Измените период или фильтры.</div>
        <table v-else>
          <thead><tr>
            <th style="width:34px"><input type="checkbox" :checked="allPageSelected" @change="togglePage"></th>
            <th class="sortable" @click="sortBy('call_datetime')">Дата и время</th>
            <th>Группа</th><th class="hide-sm">Линия</th><th>Оператор</th>
            <th class="hide-sm">Телефон</th><th>Напр.</th>
            <th class="sortable num" @click="sortBy('call_duration_seconds')">Длит.</th>
            <th class="sortable" @click="sortBy('effective_score')">Оценка</th>
            <th>Статус</th><th></th>
          </tr></thead>
          <tbody>
            <tr v-for="c in (list.items || [])" :key="c.call_key">
              <td><input type="checkbox" v-if="c.reviewable && !c.review_status" :checked="selected.has(c.call_key)" @change="toggle(c.call_key)"></td>
              <td class="mono">{{ fmtDT(c.call_datetime) }}</td>
              <td><span class="badge neutral">{{ c.group }}</span></td>
              <td class="hide-sm">{{ c.line_name }}</td>
              <td>{{ c.operator_name }}</td>
              <td class="hide-sm mono">{{ c.client_phone }}</td>
              <td>{{ dirLabel(c.direction) }}</td>
              <td class="num mono">{{ c.call_duration || '—' }}</td>
              <td>
                <span v-if="c.skipped_short" class="badge neutral">не состоялся</span>
                <score-cell v-else :value="c.effective_score" :old="c.review_status==='SCORE_CHANGED' ? c.overall_score : undefined"/>
              </td>
              <td class="status-cell">
                <span v-if="c.is_problem_call==='TRUE'" class="badge bad">проблемный</span>
                <span v-if="c.review_status==='CONFIRMED'" class="badge ok">подтверждено</span>
                <span v-else-if="c.review_status==='SCORE_CHANGED'" class="badge ok">оценка изменена</span>
                <span v-else-if="c.reviewable" class="badge warn">не проверено</span>
                <span v-else class="badge neutral">{{ c.has_analysis ? '—' : 'нет анализа' }}</span>
              </td>
              <td><button class="btn" @click="openCard(c.call_key)">Открыть</button></td>
            </tr>
          </tbody>
        </table>
        <div class="pager" v-if="list && list.total > list.per_page">
          <span class="count-note">стр. {{ list.page }} из {{ Math.ceil(list.total/list.per_page) }}</span>
          <button :disabled="f.page<=1" @click="f.page--">Назад</button>
          <button :disabled="f.page >= Math.ceil(list.total/list.per_page)" @click="f.page++">Вперёд</button>
        </div>
      </div>

      <div class="bulkbar" v-if="selected.size">
        <span>Выбрано: <b class="mono">{{ selected.size }}</b></span>
        <button class="btn-primary" :disabled="bulkBusy" @click="confirmSelected">Подтвердить оценки</button>
        <button class="ghost" @click="clearSel">Снять выделение</button>
      </div>
    </div>

    <!-- ================= КАРТОЧКА ================= -->
    <template v-if="card">
      <div class="overlay" @click="closeCard"></div>
      <div class="drawer">
        <div class="drawer-head">
          <button class="btn" :disabled="cardIndex<=0" @click="nav(-1)">←</button>
          <button class="btn" :disabled="cardIndex<0 || cardIndex>=((list?.items || []).length)-1" @click="nav(1)">→</button>
          <h3 class="mono">{{ fmtDT(card.call_datetime) }} · {{ card.operator_name || '—' }}</h3>
          <button class="close" @click="closeCard">✕</button>
        </div>

        <div class="drawer-body" v-if="card._loading">Загрузка карточки…</div>
        <div class="drawer-body" v-else>
          <div class="meta-grid">
            <div><div class="k">Группа · линия</div>{{ card.group }} · {{ card.line_name }}</div>
            <div><div class="k">Телефон клиента</div><span class="mono">{{ card.client_phone }}</span></div>
            <div><div class="k">Направление · длительность</div>{{ dirLabel(card.direction) }} · <span class="mono">{{ card.call_duration || '—' }}</span></div>
            <div><div class="k">Оценка</div><score-cell :value="card.effective_score" :old="card.review_status==='SCORE_CHANGED' ? card.overall_score : undefined"/></div>
            <div><div class="k">Целевой · запись</div>{{ card.is_target_call==='TRUE'?'да':card.is_target_call==='FALSE'?'нет':'—' }} · {{ card.is_appointment_booked==='TRUE'?'записан':'нет' }}</div>
            <div><div class="k">Статус</div>
              <div class="status-cell">
                <span v-if="card.is_problem_call==='TRUE'" class="badge bad">проблемный</span>
                <span v-if="card.review_status==='CONFIRMED'" class="badge ok">подтверждено</span>
                <span v-else-if="card.review_status==='SCORE_CHANGED'" class="badge ok">оценка изменена</span>
                <span v-else-if="card.reviewable" class="badge warn">не проверено</span>
                <span v-else class="badge neutral">{{ card.has_analysis ? '—' : 'нет анализа' }}</span>
              </div>
            </div>
            <div><div class="k">Запись разговора</div><a v-if="card.drive_url" :href="card.drive_url" target="_blank" rel="noopener">Открыть в Drive ↗</a><span v-else>—</span></div>
          </div>

          <div class="section" v-if="card.summary"><h4>Резюме</h4><div>{{ card.summary }}</div></div>

          <div class="section" v-if="card.requested_specialist || card.requested_specialist_raw || card.appointment_specialist || card.appointment_specialist_raw">
            <h4>Специалисты</h4>
            <div v-if="card.requested_specialist_raw || card.requested_specialist">Запрошен: <b>{{ card.requested_specialist || '—' }}</b>
              <span class="badge neutral" v-if="card.requested_specialist_raw">услышано: «{{ card.requested_specialist_raw }}» · {{ card.requested_specialist_match_status }}</span></div>
            <div v-if="card.appointment_specialist_raw || card.appointment_specialist">Записан к: <b>{{ card.appointment_specialist || '—' }}</b>
              <span class="badge neutral" v-if="card.appointment_specialist_raw">услышано: «{{ card.appointment_specialist_raw }}» · {{ card.appointment_specialist_match_status }}</span>
              <span v-if="card.appointment_datetime"> · {{ card.appointment_datetime }}</span></div>
          </div>

          <div class="section" v-if="card.rule_results && card.rule_results.length">
            <h4>Оценки по правилам</h4>
            <div class="rule" v-for="r in card.rule_results" :key="r.rule_id">
              <div class="rhead"><score-cell :value="r.is_na ? null : r.score"/> <span>{{ r.rule_id }}</span><span v-if="r.is_na" class="badge neutral">неприменимо</span></div>
              <div>{{ r.reason }}</div>
              <div class="quote" v-if="r.evidence">{{ r.evidence }}</div>
            </div>
            <div v-if="card.main_strength"><b>Сильная сторона:</b> {{ card.main_strength }}</div>
            <div v-if="card.main_error"><b>Основная ошибка:</b> {{ card.main_error }}</div>
            <div v-if="card.recommended_feedback"><b>Рекомендация:</b> {{ card.recommended_feedback }}</div>
          </div>

          <div class="section" v-if="card.transcript_text">
            <h4>Транскрипт</h4>
            <div class="tabs-sm">
              <button :class="{active:trTab==='all'}" @click="trTab='all'">Общий</button>
              <button :class="{active:trTab==='op'}" @click="trTab='op'">Оператор</button>
              <button :class="{active:trTab==='cl'}" @click="trTab='cl'">Клиент</button>
            </div>
            <div class="transcript">{{ trTab==='all' ? card.transcript_text : trTab==='op' ? (card.transcript_left || '—') : (card.transcript_right || '—') }}</div>
          </div>
          <div class="section" v-if="card.transcription_error"><h4>Ошибка обработки</h4><div class="error-note">{{ card.transcription_error }}</div></div>
        </div>

        <div class="verdict" v-if="card && !card._loading">
          <template v-if="card.review_status">
            <div class="done">
              <span class="badge ok">{{ card.review_status==='CONFIRMED' ? 'Оценка подтверждена' : 'Оценка изменена: ' + card.review_score }}</span>
              проверил ID <span class="mono">{{ card.reviewed_by }}</span> · {{ fmtDT(card.reviewed_at) }}
            </div>
          </template>
          <template v-else-if="card.reviewable">
            <div class="row" v-if="!verdict.mode">
              <button class="btn-primary" @click="verdict.mode='CONFIRM'">Подтвердить оценку</button>
              <button class="btn" @click="verdict.mode='CHANGE_SCORE'">Изменить оценку</button>
            </div>
            <template v-else>
              <div class="row">
                <b>{{ verdict.mode==='CONFIRM' ? 'Подтверждение оценки' : 'Новая оценка:' }}</b>
                <select v-if="verdict.mode==='CHANGE_SCORE'" v-model.number="verdict.score"><option v-for="n in 5" :value="n">{{ n }}</option></select>
              </div>
              <textarea v-model="verdict.comment" :placeholder="verdict.mode==='CONFIRM' ? 'Комментарий (необязательно)' : 'Комментарий — обязателен'"></textarea>
              <div class="error-note" v-if="verdict.error">{{ verdict.error }}</div>
              <div class="row" style="margin-top:10px">
                <button class="btn-primary" :disabled="cardBusy" @click="sendVerdict">Сохранить</button>
                <button class="btn" @click="verdict.mode=''">Отмена</button>
              </div>
            </template>
          </template>
          <template v-else><div class="done">Звонок без анализа — проверка недоступна.</div></template>
        </div>
      </div>
    </template>

    <div class="toast" v-if="toast">{{ toast }}</div>
  </template>
  `
});
// Необработанное исключение в render-функции оставляет #app пустым (серый экран).
// Показываем плашку с текстом ошибки, чтобы пользователь понял, что делать.
app.config.errorHandler = (err) => {
  console.error(err);
  const el = document.getElementById('app-fatal');
  if (el) { el.style.display = 'block'; el.querySelector('.msg').textContent = String(err); }
};

app.mount('#app');
