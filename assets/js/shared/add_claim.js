const clientSelect = document.getElementById('client_id');
const policySelect = document.getElementById('policy_id');

clientSelect.addEventListener('change', function() {
  const clientId = this.value;
  policySelect.innerHTML = '<option value="">Loading...</option>';

  if (!clientId) {
    policySelect.innerHTML = '<option value="">— Select client first —</option>';
    return;
  }

  fetch('add_claim.php?ajax_policies=1&client_id=' + clientId)
    .then(r => r.json())
    .then(data => {
      if (data.length === 0) {
        policySelect.innerHTML = '<option value="">No active policies found</option>';
        return;
      }
      policySelect.innerHTML = '<option value="">— Select Policy —</option>';
      data.forEach(p => {
        const label = p.policy_number + ' — ' + (p.plate_number || 'No plate') + ' ' + (p.make || '') + ' ' + (p.model || '') + ' (expires ' + p.policy_end + ')';
        policySelect.innerHTML += '<option value="' + p.policy_id + '">' + label + '</option>';
      });
    })
    .catch(() => {
      policySelect.innerHTML = '<option value="">Error loading policies</option>';
    });
});

// Re-select policy if validation failed (savedClient/savedPolicy set inline by PHP)
if (typeof savedClient !== 'undefined' && savedClient) {
  clientSelect.value = savedClient;
  clientSelect.dispatchEvent(new Event('change'));
  setTimeout(() => { if (savedPolicy) policySelect.value = savedPolicy; }, 600);
}
