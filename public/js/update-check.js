// Checks GitHub for a newer release and reveals the footer "(Update available)" link.
(function () {
  const link = document.getElementById('update-link');
  if (!link) {
    return;
  }
  fetch('/update-check.php', { headers: { Accept: 'application/json' } })
    .then((response) => (response.ok ? response.json() : null))
    .then((data) => {
      if (!data || !data.update_available || !data.latest) {
        return;
      }
      if (data.release_url) {
        link.href = data.release_url;
        link.title = 'Version ' + data.latest + ' is available';
      }
      link.hidden = false;
    })
    .catch(() => {});
})();
