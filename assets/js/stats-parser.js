/*
 * dbaronald.nl — Statistics Parser
 * Parses SET STATISTICS IO/TIME ON output entirely client-side and flags
 * common red flags. No network calls in this file — it must keep working
 * even if the "email me" backend (wordpress/stats-parser-handler.php) is
 * down.
 *
 * Deploys via the normal dbaronald.com git-push pipeline (unlike the
 * wordpress/ files, which are hand-pasted). The live WordPress page loads
 * this straight from https://dbaronald.com/assets/js/stats-parser.js via a
 * plain cross-origin <script src> — see CLAUDE.md "Adding the Statistics
 * Parser tool".
 */
(function () {
  "use strict";

  var STRINGS = {
    nl: {
      parse: "Parsen",
      clear: "Wissen",
      example: "Voorbeeld laden",
      copy: "Resultaat kopiëren",
      copied: "Gekopieerd!",
      inputPlaceholder: "Plak hier je STATISTICS IO/TIME-output...",
      emailBtn: "E-mail me het volledige rapport",
      emailPlaceholder: "jouw@email.nl",
      emailSending: "Versturen…",
      emailSent: "Verstuurd — check je inbox.",
      emailError: "Versturen mislukt. Probeer het later opnieuw.",
      emailCaptchaMissing: "Voltooi eerst de verificatie hierboven.",
      noInput: "Plak eerst STATISTICS IO/TIME-output hierboven.",
      noMatch: "Geen STATISTICS IO of TIME-regels herkend in deze tekst.",
      table: "Tabel",
      scans: "Scans",
      logical: "Logical reads",
      physical: "Physical reads",
      readAhead: "Read-ahead",
      lob: "LOB logical",
      total: "TOTAAL",
      cpuElapsed: "CPU-tijd = {cpu} ms, verstreken tijd = {elapsed} ms",
      findings: "Signalen",
      noFindings: "Geen bijzonderheden gevonden in deze output.",
      msgPhysical: "{table}: physical reads aanwezig — koude cache of geheugendruk, de moeite van een tweede blik waard.",
      msgLob: "{table}: LOB logical reads aanwezig — worden grote kolommen (varchar(max)/nvarchar(max)/varbinary(max)) echt gebruikt door deze query?",
      msgWork: "{table}: work table/file — er gebeurt spool-, sort- of hash-werk in tempdb.",
      msgScan: "{table}: scan count {count} — mogelijk een nested loop die deze tabel herhaaldelijk raakt. Het executieplan bevestigt dit.",
      msgCpuHigh: "CPU-tijd is veel hoger dan de verstreken tijd — mogelijk parallellisme-overhead voor deze batch.",
      msgElapsedHigh: "Verstreken tijd is veel hoger dan de CPU-tijd — de query heeft waarschijnlijk gewacht (locks, I/O, resources)."
    },
    en: {
      parse: "Parse",
      clear: "Clear",
      example: "Load example",
      copy: "Copy result",
      copied: "Copied!",
      inputPlaceholder: "Paste your STATISTICS IO/TIME output here...",
      emailBtn: "Email me the full report",
      emailPlaceholder: "you@email.com",
      emailSending: "Sending…",
      emailSent: "Sent — check your inbox.",
      emailError: "Sending failed. Please try again later.",
      emailCaptchaMissing: "Please complete the verification above first.",
      noInput: "Paste STATISTICS IO/TIME output above first.",
      noMatch: "No STATISTICS IO or TIME lines recognized in this text.",
      table: "Table",
      scans: "Scans",
      logical: "Logical reads",
      physical: "Physical reads",
      readAhead: "Read-ahead",
      lob: "LOB logical",
      total: "TOTAL",
      cpuElapsed: "CPU time = {cpu} ms, elapsed time = {elapsed} ms",
      findings: "Findings",
      noFindings: "No red flags found in this output.",
      msgPhysical: "{table}: physical reads present — cold cache or memory pressure, worth a second look.",
      msgLob: "{table}: LOB logical reads present — are large columns (varchar(max)/nvarchar(max)/varbinary(max)) actually needed by this query?",
      msgWork: "{table}: work table/file — spool, sort or hash work is happening in tempdb.",
      msgScan: "{table}: scan count {count} — possibly a nested loop hitting this table repeatedly. Check the execution plan to confirm.",
      msgCpuHigh: "CPU time is much higher than elapsed time — possible parallelism overhead for this batch.",
      msgElapsedHigh: "Elapsed time is much higher than CPU time — the query likely waited on something (locks, I/O, resources)."
    }
  };

  var EXAMPLE =
    "Table 'Orders'. Scan count 3, logical reads 1583, physical reads 4, " +
    "page server reads 0, read-ahead reads 12, page server read-ahead reads 0, " +
    "lob logical reads 0, lob physical reads 0, lob page server reads 0, " +
    "lob read-ahead reads 0, lob page server read-ahead reads 0.\n" +
    "Table 'Worktable'. Scan count 1, logical reads 842, physical reads 0, " +
    "page server reads 0, read-ahead reads 0, page server read-ahead reads 0, " +
    "lob logical reads 0, lob physical reads 0, lob page server reads 0, " +
    "lob read-ahead reads 0, lob page server read-ahead reads 0.\n" +
    "Table 'Customer'. Scan count 1, logical reads 420, physical reads 2, " +
    "page server reads 0, read-ahead reads 6, page server read-ahead reads 0, " +
    "lob logical reads 18, lob physical reads 0, lob page server reads 0, " +
    "lob read-ahead reads 0, lob page server read-ahead reads 0.\n\n" +
    " SQL Server Execution Times:\n" +
    "   CPU time = 47 ms,  elapsed time = 156 ms.";

  // ---- parsing ---------------------------------------------------------

  var TABLE_RE = /Table\s+'([^']+)'\.\s*Scan count (\d+),\s*logical reads (\d+),\s*physical reads (\d+),\s*(?:page server reads \d+,\s*)?read-ahead reads (\d+),\s*(?:page server read-ahead reads \d+,\s*)?lob logical reads (\d+),\s*lob physical reads (\d+)/gi;
  var TIME_RE = /CPU time\s*=\s*(\d+)\s*ms,\s*elapsed time\s*=\s*(\d+)\s*ms/gi;

  function parseStats(text) {
    var tables = [];
    var m;
    TABLE_RE.lastIndex = 0;
    while ((m = TABLE_RE.exec(text)) !== null) {
      tables.push({
        name: m[1],
        scans: parseInt(m[2], 10),
        logical: parseInt(m[3], 10),
        physical: parseInt(m[4], 10),
        readAhead: parseInt(m[5], 10),
        lobLogical: parseInt(m[6], 10),
        lobPhysical: parseInt(m[7], 10)
      });
    }

    var cpu = 0, elapsed = 0, sawTime = false;
    TIME_RE.lastIndex = 0;
    while ((m = TIME_RE.exec(text)) !== null) {
      cpu += parseInt(m[1], 10);
      elapsed += parseInt(m[2], 10);
      sawTime = true;
    }

    return { tables: tables, cpu: cpu, elapsed: elapsed, sawTime: sawTime };
  }

  // Same-named tables can appear multiple times (repeated statement
  // batches, e.g. in a loop) — sum them, matching statisticsparser.com.
  function mergeByTable(tables) {
    var byName = {};
    var order = [];
    tables.forEach(function (t) {
      if (!byName[t.name]) {
        byName[t.name] = { name: t.name, scans: 0, logical: 0, physical: 0, readAhead: 0, lobLogical: 0, lobPhysical: 0 };
        order.push(t.name);
      }
      var agg = byName[t.name];
      agg.scans += t.scans;
      agg.logical += t.logical;
      agg.physical += t.physical;
      agg.readAhead += t.readAhead;
      agg.lobLogical += t.lobLogical;
      agg.lobPhysical += t.lobPhysical;
    });
    return order.map(function (n) { return byName[n]; });
  }

  // ---- suggestions -------------------------------------------------------
  // Facts only — every finding is derived directly from parsed numbers, no
  // guessing beyond what's stated in the message itself.

  function buildFindings(merged, cpu, elapsed, sawTime, s) {
    var findings = [];
    var maxScans = 0;
    merged.forEach(function (t) { if (t.scans > maxScans) maxScans = t.scans; });

    merged.forEach(function (t) {
      if (t.physical > 0) {
        findings.push({ level: "amber", text: s.msgPhysical.replace("{table}", t.name) });
      }
      if (t.lobLogical > 0) {
        findings.push({ level: "accent", text: s.msgLob.replace("{table}", t.name) });
      }
      if (/^#|work(table|file)/i.test(t.name)) {
        findings.push({ level: "amber", text: s.msgWork.replace("{table}", t.name) });
      }
      if (t.scans > 1 && merged.length > 1 && t.scans === maxScans) {
        findings.push({ level: "accent", text: s.msgScan.replace("{table}", t.name).replace("{count}", t.scans) });
      }
    });

    if (sawTime && elapsed > 0) {
      if (cpu > elapsed * 1.5) {
        findings.push({ level: "accent", text: s.msgCpuHigh });
      } else if (elapsed > cpu * 3 && elapsed - cpu > 50) {
        findings.push({ level: "amber", text: s.msgElapsedHigh });
      }
    }

    return findings;
  }

  // ---- rendering -----------------------------------------------------

  function fmt(n) { return n.toLocaleString(); }

  function renderResults(root, parsed, s) {
    var merged = mergeByTable(parsed.tables);
    var out = root.querySelector(".sp-results");

    if (merged.length === 0 && !parsed.sawTime) {
      out.innerHTML = '<p class="sp-empty">' + s.noMatch + "</p>";
      return null;
    }

    var totals = merged.reduce(function (a, t) {
      a.scans += t.scans; a.logical += t.logical; a.physical += t.physical; a.readAhead += t.readAhead;
      return a;
    }, { scans: 0, logical: 0, physical: 0, readAhead: 0 });

    var html = "";

    if (merged.length) {
      html += '<div class="sp-table-wrap"><table class="sp-table"><thead><tr>' +
        "<th>" + s.table + "</th><th>" + s.scans + "</th><th>" + s.logical + "</th><th>" +
        s.physical + "</th><th>" + s.readAhead + "</th><th>" + s.lob + "</th></tr></thead><tbody>";
      merged.forEach(function (t) {
        html += "<tr" + (t.physical > 0 ? ' class="sp-flag-row"' : "") + "><td>" + escapeHtml(t.name) + "</td><td>" +
          fmt(t.scans) + "</td><td>" + fmt(t.logical) + "</td><td>" + fmt(t.physical) + "</td><td>" +
          fmt(t.readAhead) + "</td><td>" + fmt(t.lobLogical) + "</td></tr>";
      });
      html += '<tr class="sp-total-row"><td>' + s.total + "</td><td>" + fmt(totals.scans) + "</td><td>" +
        fmt(totals.logical) + "</td><td>" + fmt(totals.physical) + "</td><td>" + fmt(totals.readAhead) +
        "</td><td>—</td></tr></tbody></table></div>";
    }

    if (parsed.sawTime) {
      html += '<p class="sp-time">' + s.cpuElapsed.replace("{cpu}", fmt(parsed.cpu)).replace("{elapsed}", fmt(parsed.elapsed)) + "</p>";
    }

    var findings = buildFindings(merged, parsed.cpu, parsed.elapsed, parsed.sawTime, s);
    html += '<h3 class="sp-findings-title">' + s.findings + "</h3>";
    if (findings.length === 0) {
      html += '<p class="sp-empty">' + s.noFindings + "</p>";
    } else {
      html += '<ul class="sp-findings">';
      findings.forEach(function (f) {
        html += '<li class="pill pill-' + f.level + '">' + escapeHtml(f.text) + "</li>";
      });
      html += "</ul>";
    }

    out.innerHTML = html;

    return {
      tables: merged,
      totals: totals,
      cpu: parsed.cpu,
      elapsed: parsed.elapsed,
      sawTime: parsed.sawTime,
      findings: findings
    };
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function resultsToPlainText(result, s) {
    var lines = [];
    result.tables.forEach(function (t) {
      lines.push(t.name + "\t" + t.scans + "\t" + t.logical + "\t" + t.physical + "\t" + t.readAhead + "\t" + t.lobLogical);
    });
    if (result.sawTime) {
      lines.push("");
      lines.push(s.cpuElapsed.replace("{cpu}", fmt(result.cpu)).replace("{elapsed}", fmt(result.elapsed)));
    }
    if (result.findings.length) {
      lines.push("");
      result.findings.forEach(function (f) { lines.push("- " + f.text); });
    }
    return lines.join("\n");
  }

  // ---- language switching ----------------------------------------------
  // Long-form prose (the guidance panel) is duplicated per language in the
  // markup and toggled via [data-lang-text]. Short interactive-control
  // labels are single DOM nodes whose text/placeholder is set here from
  // STRINGS, so there is exactly one real <textarea>/<input>/<button> per
  // control (duplicating those would silently break querySelector, which
  // only ever finds the first match).

  function applyLangText(root, lang) {
    var s = STRINGS[lang];

    var prose = root.querySelectorAll("[data-lang-text]");
    for (var i = 0; i < prose.length; i++) {
      prose[i].hidden = prose[i].getAttribute("data-lang-text") !== lang;
    }

    var links = root.querySelectorAll(".sp-lang-switch [data-set-lang]");
    for (var j = 0; j < links.length; j++) {
      links[j].classList.toggle("active", links[j].getAttribute("data-set-lang") === lang);
    }

    setText(root.querySelector(".sp-parse"), s.parse);
    setText(root.querySelector(".sp-clear"), s.clear);
    setText(root.querySelector(".sp-example"), s.example);
    setText(root.querySelector(".sp-copy"), s.copy);

    var textarea = root.querySelector(".sp-input");
    if (textarea) textarea.placeholder = s.inputPlaceholder;

    var emailForm = root.querySelector(".sp-email-form");
    if (emailForm) {
      setText(emailForm.querySelector("button[type=submit]"), s.emailBtn);
      var emailInput = emailForm.querySelector(".sp-email-input");
      if (emailInput) emailInput.placeholder = s.emailPlaceholder;
    }
  }

  function setText(el, text) {
    if (el) el.textContent = text;
  }

  // ---- wiring ----------------------------------------------------------

  function init(root) {
    var lang = root.getAttribute("data-lang") === "en" ? "en" : "nl";
    var s = STRINGS[lang];
    var textarea = root.querySelector(".sp-input");
    applyLangText(root, lang);

    var langLinks = root.querySelectorAll(".sp-lang-switch [data-set-lang]");
    for (var li = 0; li < langLinks.length; li++) {
      langLinks[li].addEventListener("click", function (evt) {
        evt.preventDefault();
        lang = this.getAttribute("data-set-lang") === "en" ? "en" : "nl";
        s = STRINGS[lang];
        root.setAttribute("data-lang", lang);
        applyLangText(root, lang);
        if (textarea.value.trim()) runParse();
      });
    }

    var parseBtn = root.querySelector(".sp-parse");
    var clearBtn = root.querySelector(".sp-clear");
    var exampleBtn = root.querySelector(".sp-example");
    var copyBtn = root.querySelector(".sp-copy");
    var emailForm = root.querySelector(".sp-email-form");
    var emailInput = root.querySelector(".sp-email-input");
    var emailStatus = root.querySelector(".sp-email-status");
    var lastResult = null;
    var lastRaw = "";

    function runParse() {
      var text = textarea.value;
      if (!text.trim()) {
        root.querySelector(".sp-results").innerHTML = '<p class="sp-empty">' + s.noInput + "</p>";
        lastResult = null;
        return;
      }
      lastRaw = text;
      lastResult = renderResults(root, parseStats(text), s);
      if (emailForm) {
        emailForm.classList.toggle("sp-hidden", !lastResult);
      }
    }

    parseBtn.addEventListener("click", runParse);
    clearBtn.addEventListener("click", function () {
      textarea.value = "";
      root.querySelector(".sp-results").innerHTML = "";
      lastResult = null;
      if (emailForm) emailForm.classList.add("sp-hidden");
    });
    exampleBtn.addEventListener("click", function () {
      textarea.value = EXAMPLE;
      runParse();
    });

    if (copyBtn) {
      copyBtn.addEventListener("click", function () {
        if (!lastResult) return;
        var text = resultsToPlainText(lastResult, s);
        navigator.clipboard.writeText(text).then(function () {
          var original = copyBtn.textContent;
          copyBtn.textContent = s.copied;
          setTimeout(function () { copyBtn.textContent = original; }, 1500);
        });
      });
    }

    if (emailForm) {
      emailForm.addEventListener("submit", function (evt) {
        evt.preventDefault();
        if (!lastResult) return;
        var email = emailInput.value.trim();
        if (!email) return;

        // Cloudflare Turnstile injects a hidden input named
        // "cf-turnstile-response" into this form once solved (see the
        // .cf-turnstile div in stats-parser-page.html / the PHP shortcode).
        var turnstileInput = emailForm.querySelector('[name="cf-turnstile-response"]');
        var turnstileToken = turnstileInput ? turnstileInput.value : "";
        if (!turnstileToken) {
          emailStatus.textContent = s.emailCaptchaMissing;
          return;
        }

        var ajaxUrl = root.getAttribute("data-ajax-url");
        var nonce = root.getAttribute("data-nonce");
        emailStatus.textContent = s.emailSending;

        var body = new URLSearchParams();
        body.set("action", "dbaronald_stats_parser_email");
        body.set("nonce", nonce);
        body.set("email", email);
        body.set("raw", lastRaw);
        body.set("lang", lang);
        body.set("turnstile_token", turnstileToken);

        fetch(ajaxUrl, { method: "POST", body: body })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            emailStatus.textContent = data && data.success ? s.emailSent : s.emailError;
          })
          .catch(function () {
            emailStatus.textContent = s.emailError;
          })
          .finally(function () {
            if (window.turnstile && root.querySelector(".cf-turnstile")) {
              window.turnstile.reset(root.querySelector(".cf-turnstile"));
            }
          });
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var roots = document.querySelectorAll(".stats-parser");
    for (var i = 0; i < roots.length; i++) init(roots[i]);
  });
})();
