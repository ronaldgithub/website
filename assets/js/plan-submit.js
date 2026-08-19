/*
 * dbaronald.nl — "Submit an execution plan" intake form.
 * Emails the uploaded .sqlplan file to Ronald via the WordPress AJAX handler
 * (wordpress/plan-submit-handler.php) — no client-side or server-side
 * analysis here, just delivery into the existing Power Automate → OneDrive →
 * SQLPerformanceViewer pipeline. Deploys via the normal dbaronald.com
 * git-push pipeline — see CLAUDE.md "Adding the plan submission form".
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.querySelector(".plan-submit");
    if (!root) return;

    var form = root.querySelector(".ps-form");
    var fileInput = root.querySelector(".ps-file-input");
    var emailInput = root.querySelector(".ps-email-input");
    var noteInput = root.querySelector(".ps-note-input");
    var submitBtn = root.querySelector(".ps-submit");
    var status = root.querySelector(".ps-status");

    form.addEventListener("submit", function (evt) {
      evt.preventDefault();

      var file = fileInput.files && fileInput.files[0];
      if (!file) return;

      var ajaxUrl = root.getAttribute("data-ajax-url");
      var nonce = root.getAttribute("data-nonce");

      status.className = "ps-status";
      status.textContent = "Versturen…";
      submitBtn.disabled = true;

      var body = new FormData();
      body.append("action", "dbaronald_plan_submit_email");
      body.append("nonce", nonce);
      body.append("email", emailInput.value.trim());
      body.append("note", noteInput.value.trim());
      body.append("plan_file", file);

      fetch(ajaxUrl, { method: "POST", body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success) {
            status.className = "ps-status ps-ok";
            status.textContent = "Verstuurd — ik neem het door en stuur je een link naar de analyse.";
            form.reset();
          } else {
            status.className = "ps-status ps-err";
            status.textContent = (data && data.error === "invalid_file")
              ? "Dit lijkt geen geldig .sqlplan-bestand — controleer of je de juiste export hebt geüpload."
              : "Versturen mislukt. Probeer het later opnieuw of mail me rechtstreeks.";
          }
        })
        .catch(function () {
          status.className = "ps-status ps-err";
          status.textContent = "Versturen mislukt. Probeer het later opnieuw of mail me rechtstreeks.";
        })
        .finally(function () {
          submitBtn.disabled = false;
        });
    });
  });
})();
