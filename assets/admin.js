(() => {
  const root = document.querySelector('.acfcm-admin');
  if (!root) return;
  root.querySelectorAll('table.widefat').forEach(table => {
    const headers = [...table.querySelectorAll('thead th')].map(th => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach(row => {
      const site = row.cells[0]?.textContent.trim() || '';
      [...row.cells].forEach((cell, i) => {
        cell.dataset.label = headers[i] || '';
        cell.querySelectorAll('input,select').forEach(input => {
          if (!input.hasAttribute('aria-label')) input.setAttribute('aria-label', `${headers[i]} — ${site}`);
        });
      });
    });
    table.classList.add('acfcm-responsive');
  });
  root.querySelectorAll('.acfcm-nav a').forEach(a => a.addEventListener('click', () => {
    const target = document.getElementById(a.hash.slice(1));
    if (target?.tagName === 'DETAILS') target.open = true;
  }));
  root.querySelectorAll('form.acfcm-migrate').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const status = form.querySelector('.acfcm-progress') || document.getElementById(form.id + '-status');
    const button = event.submitter || form.querySelector('button');
    const data = new FormData(form);
    const controls = [...root.querySelectorAll('button')].filter(control => control.form?.classList.contains('acfcm-migrate'));
    if (root.dataset.installing) return;
    root.dataset.installing = 'true';
    controls.forEach(control => { control.disabled = true; });
    status.textContent = 'Preparing backup and checking this zone…';
    form.setAttribute('aria-busy', 'true'); button.disabled = true; status.focus();
    try {
      // User-initiated only. Every request performs at most one journaled rule mutation.
      for (let tick = 0; tick < 45; tick++) {
        status.textContent = tick ? 'Applying the next verified step… Keep this page open.' : 'Preparing backup and checking this zone…';
        const response = await fetch(form.dataset.ajax, {method: 'POST', body: data, credentials: 'same-origin'});
        const result = await response.json();
        if (!result.success) throw new Error(result.data?.message || 'Request failed. Review the saved migration before resuming.');
        const state = result.data;
        status.textContent = `${state.status}: ${state.cursor || 0} of ${state.total || 0} steps. ${state.error || ''}`;
        if (state.status !== 'running') {
          if (['complete', 'rolled_back', 'review'].includes(state.status)) location.reload();
          return;
        }
        data.set('policy_action', 'step');
      }
      throw new Error('Request limit reached. Review the migration status and resume explicitly.');
    } catch (error) {
      status.textContent = `${error.message} No automatic retry was sent. Reload to review the saved state.`;
    } finally {
      form.removeAttribute('aria-busy'); delete root.dataset.installing; controls.forEach(control => { control.disabled = false; });
    }
  }));
})();
