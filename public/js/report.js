// Confirms and submits report deletion on the report detail page.
(function () {
  const deleteForm = document.getElementById('delete-form');
  if (!deleteForm) {
    return;
  }
  deleteForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const confirmed = window.confirm('Are you sure?');
    if (!confirmed) {
      return;
    }
    const formData = new FormData(deleteForm);
    const response = await fetch('/delete-report.php', {
      method: 'POST',
      body: formData,
    });
    if (response.ok) {
      const data = await response.json();
      if (data && data.ok) {
        window.location.href = '/';
        return;
      }
    }
    window.alert('Deletion failed.');
  });
})();
