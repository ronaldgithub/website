/*
 * dbaronald.nl — Execution Plan Analyzer
 * Parses SQL Server showplan XML (.sqlplan / "Show Execution Plan XML")
 * entirely client-side and flags common red flags. No network calls in
 * this file — it must keep working even if the "email me" backend
 * (wordpress/execution-plan-analyzer-handler.php) is down.
 *
 * Deploys via the normal dbaronald.com git-push pipeline (unlike the
 * wordpress/ files, which are hand-pasted). The live WordPress page loads
 * this straight from https://dbaronald.com/assets/js/execution-plan-analyzer.js
 * via a plain cross-origin <script src> — see CLAUDE.md "Adding the
 * Execution Plan Analyzer tool".
 */
(function () {
  "use strict";

  var SHOWPLAN_NS = "http://schemas.microsoft.com/sqlserver/2004/07/showplan";

  var STRINGS = {
    nl: {
      analyze: "Analyseer",
      clear: "Wissen",
      example: "Voorbeeld laden",
      copy: "Resultaat kopiëren",
      copied: "Gekopieerd!",
      inputPlaceholder: "Plak hier de Show Execution Plan XML, of upload een .sqlplan-bestand...",
      upload: "Of upload een .sqlplan-bestand",
      emailBtn: "E-mail me het volledige rapport",
      emailPlaceholder: "jouw@email.nl",
      emailSending: "Versturen…",
      emailSent: "Verstuurd — check je inbox.",
      emailError: "Versturen mislukt. Probeer het later opnieuw.",
      emailCaptchaMissing: "Voltooi eerst de verificatie hierboven.",
      noInput: "Plak of upload eerst een executieplan.",
      noMatch: "Dit lijkt geen geldige SQL Server showplan XML — controleer of je de XML (niet de grafische weergave) hebt geplakt.",
      findings: "Signalen",
      noFindings: "Geen bijzonderheden gevonden in dit plan.",
      opSummary: "Operators",
      opCol: "Operator",
      estCol: "Geschat",
      actCol: "Actueel",
      msgWarnSpill: "{op} (node {id}): spill naar tempdb — het geheugengrant was te klein voor deze sort/hash. Overweeg de geheugengrant of de cardinaliteitsschatting te verbeteren.",
      msgWarnNoJoinPredicate: "{op} (node {id}): join zonder join-predicaat (cross join) — controleer of dit bedoeld is.",
      msgWarnConvert: "{op} (node {id}): impliciete conversie ({expr}) — dit kan een index onbruikbaar maken of de cardinaliteitsschatting verstoren.",
      msgWarnNoStats: "{op} (node {id}): kolommen zonder statistieken ({cols}) — de optimizer gokt hier.",
      msgSkewHigh: "{op} (node {id}): geschat {est} rijen, actueel {act} — een afwijking van {ratio}x. Het plan kan hierdoor een verkeerde join- of operatorkeuze hebben gemaakt.",
      msgLookup: "{op} (node {id}): {count}x uitgevoerd — mogelijk ontbreekt een covering index die deze lookups overbodig maakt.",
      msgMissingIndex: "Ontbrekende index gesuggereerd op {table} (impact {impact}%): {cols}. Controleer dit voordat je het toepast — de DTA-suggestie is niet altijd optimaal.",
      msgParallelSmall: "Parallel plan (DOP {dop}) voor een kleine resultset ({rows} rijen) — de overhead van parallellisme kan hier groter zijn dan de winst."
    },
    en: {
      analyze: "Analyze",
      clear: "Clear",
      example: "Load example",
      copy: "Copy result",
      copied: "Copied!",
      inputPlaceholder: "Paste the Show Execution Plan XML here, or upload a .sqlplan file...",
      upload: "Or upload a .sqlplan file",
      emailBtn: "Email me the full report",
      emailPlaceholder: "you@email.com",
      emailSending: "Sending…",
      emailSent: "Sent — check your inbox.",
      emailError: "Sending failed. Please try again later.",
      emailCaptchaMissing: "Please complete the verification above first.",
      noInput: "Paste or upload an execution plan first.",
      noMatch: "This doesn't look like valid SQL Server showplan XML — make sure you pasted the XML (not the graphical view).",
      findings: "Findings",
      noFindings: "No red flags found in this plan.",
      opSummary: "Operators",
      opCol: "Operator",
      estCol: "Estimated",
      actCol: "Actual",
      msgWarnSpill: "{op} (node {id}): spilled to tempdb — the memory grant was too small for this sort/hash. Consider the memory grant or improving the cardinality estimate.",
      msgWarnNoJoinPredicate: "{op} (node {id}): join with no join predicate (cross join) — check whether this is intentional.",
      msgWarnConvert: "{op} (node {id}): implicit conversion ({expr}) — this can make an index unusable or skew the cardinality estimate.",
      msgWarnNoStats: "{op} (node {id}): columns with no statistics ({cols}) — the optimizer is guessing here.",
      msgSkewHigh: "{op} (node {id}): estimated {est} rows, actual {act} — a {ratio}x deviation. The plan may have made a wrong join or operator choice because of this.",
      msgLookup: "{op} (node {id}): executed {count}x — a covering index that removes these lookups may be missing.",
      msgMissingIndex: "Missing index suggested on {table} (impact {impact}%): {cols}. Verify before applying — the DTA suggestion isn't always optimal.",
      msgParallelSmall: "Parallel plan (DOP {dop}) for a small result set ({rows} rows) — parallelism overhead may outweigh the benefit here."
    }
  };

  var EXAMPLE =
    '<?xml version="1.0" encoding="utf-16"?>\n' +
    '<ShowPlanXML xmlns="http://schemas.microsoft.com/sqlserver/2004/07/showplan" Version="1.539" Build="16.0.4145.4">\n' +
    '  <BatchSequence><Batch><Statements><StmtSimple StatementText="SELECT ..." StatementEstRows="1" StatementSubTreeCost="12.4">\n' +
    '    <QueryPlan DegreeOfParallelism="1" MemoryGrant="4096">\n' +
    '      <MissingIndexes>\n' +
    '        <MissingIndexGroup Impact="87.6">\n' +
    '          <MissingIndex Database="[StackOverflow]" Schema="[dbo]" Table="[Posts]">\n' +
    '            <ColumnGroup Usage="EQUALITY"><Column Name="[OwnerUserId]" ColumnId="5" /></ColumnGroup>\n' +
    '            <ColumnGroup Usage="INCLUDE"><Column Name="[Score]" ColumnId="8" /></ColumnGroup>\n' +
    '          </MissingIndex>\n' +
    '        </MissingIndexGroup>\n' +
    '      </MissingIndexes>\n' +
    '      <RelOp NodeId="0" PhysicalOp="Hash Match" LogicalOp="Inner Join" EstimateRows="12">\n' +
    '        <RunTimeInformation><RunTimeCountersPerThread Thread="0" ActualRows="48219" ActualExecutions="1" /></RunTimeInformation>\n' +
    '        <Warnings><SpillToTempDb SpillLevel="1" /></Warnings>\n' +
    '        <RelOp NodeId="1" PhysicalOp="Clustered Index Seek" LogicalOp="Key Lookup" EstimateRows="1">\n' +
    '          <RunTimeInformation><RunTimeCountersPerThread Thread="0" ActualRows="48219" ActualExecutions="48219" /></RunTimeInformation>\n' +
    '        </RelOp>\n' +
    '        <RelOp NodeId="2" PhysicalOp="Index Scan" LogicalOp="Index Scan" EstimateRows="120000">\n' +
    '          <RunTimeInformation><RunTimeCountersPerThread Thread="0" ActualRows="118532" ActualExecutions="1" /></RunTimeInformation>\n' +
    '          <Warnings><PlanAffectingConvert Expression="CONVERT_IMPLICIT(nvarchar(4000),[Posts].[OwnerUserId],0)" ConvertIssue="Seek Plan" /></Warnings>\n' +
    '        </RelOp>\n' +
    '      </RelOp>\n' +
    '    </QueryPlan>\n' +
    '  </StmtSimple></Statements></Batch></BatchSequence>\n' +
    '</ShowPlanXML>';

  // ---- parsing -----------------------------------------------------

  function opLabel(relop) {
    var physical = relop.getAttribute("PhysicalOp") || "?";
    var logical = relop.getAttribute("LogicalOp") || "";
    return logical && logical !== physical ? physical + " (" + logical + ")" : physical;
  }

  function sumThreadAttr(runtimeInfoEl, attr) {
    if (!runtimeInfoEl) return null;
    var threads = runtimeInfoEl.getElementsByTagNameNS(SHOWPLAN_NS, "RunTimeCountersPerThread");
    if (threads.length === 0) return null;
    var total = 0;
    for (var i = 0; i < threads.length; i++) {
      var v = threads[i].getAttribute(attr);
      if (v !== null) total += parseFloat(v);
    }
    return total;
  }

  function firstChildNS(el, tag) {
    var found = el.getElementsByTagNameNS(SHOWPLAN_NS, tag);
    // only direct children matter for RunTimeInformation/Warnings — but
    // getElementsByTagNameNS is subtree-wide, so guard against picking up
    // a nested RelOp's own child of the same tag name.
    for (var i = 0; i < found.length; i++) {
      if (found[i].parentNode === el) return found[i];
    }
    return null;
  }

  function parsePlan(xmlText) {
    var doc;
    try {
      doc = new DOMParser().parseFromString(xmlText, "text/xml");
    } catch (e) {
      return null;
    }
    if (!doc || doc.getElementsByTagName("parsererror").length > 0) return null;
    var root = doc.documentElement;
    if (!root || root.namespaceURI !== SHOWPLAN_NS) return null;

    var relOps = doc.getElementsByTagNameNS(SHOWPLAN_NS, "RelOp");
    if (relOps.length === 0) return null;

    var queryPlanEls = doc.getElementsByTagNameNS(SHOWPLAN_NS, "QueryPlan");
    var dop = 1;
    if (queryPlanEls.length > 0) {
      var dopAttr = queryPlanEls[0].getAttribute("DegreeOfParallelism");
      if (dopAttr !== null) dop = parseInt(dopAttr, 10);
    }

    var operators = [];
    for (var i = 0; i < relOps.length; i++) {
      var relop = relOps[i];
      var runtimeInfo = firstChildNS(relop, "RunTimeInformation");
      var warningsEl = firstChildNS(relop, "Warnings");
      operators.push({
        nodeId: relop.getAttribute("NodeId"),
        physicalOp: relop.getAttribute("PhysicalOp") || "?",
        logicalOp: relop.getAttribute("LogicalOp") || "",
        label: opLabel(relop),
        estimateRows: relop.getAttribute("EstimateRows") !== null ? parseFloat(relop.getAttribute("EstimateRows")) : null,
        actualRows: sumThreadAttr(runtimeInfo, "ActualRows"),
        actualExecutions: sumThreadAttr(runtimeInfo, "ActualExecutions"),
        warnings: warningsEl,
        isTopLevel: relop.parentNode && relop.parentNode.localName === "QueryPlan"
      });
    }

    var missingIndexGroups = [];
    var miGroups = doc.getElementsByTagNameNS(SHOWPLAN_NS, "MissingIndexGroup");
    for (var g = 0; g < miGroups.length; g++) {
      var group = miGroups[g];
      var mi = group.getElementsByTagNameNS(SHOWPLAN_NS, "MissingIndex")[0];
      if (!mi) continue;
      var table = (mi.getAttribute("Table") || "").replace(/[\[\]]/g, "");
      var cols = [];
      var colGroups = mi.getElementsByTagNameNS(SHOWPLAN_NS, "ColumnGroup");
      for (var cg = 0; cg < colGroups.length; cg++) {
        var usage = colGroups[cg].getAttribute("Usage");
        var colNames = [];
        var colEls = colGroups[cg].getElementsByTagNameNS(SHOWPLAN_NS, "Column");
        for (var c = 0; c < colEls.length; c++) {
          colNames.push((colEls[c].getAttribute("Name") || "").replace(/[\[\]]/g, ""));
        }
        cols.push(usage + ": " + colNames.join(", "));
      }
      missingIndexGroups.push({ table: table, impact: parseFloat(group.getAttribute("Impact") || "0"), cols: cols.join(" · ") });
    }

    return { operators: operators, missingIndexGroups: missingIndexGroups, dop: dop };
  }

  // ---- findings ----------------------------------------------------

  function buildFindings(plan, s) {
    var findings = [];

    plan.operators.forEach(function (op) {
      if (op.warnings) {
        var w = op.warnings;
        if (w.getAttribute("NoJoinPredicate") === "true" || w.getElementsByTagNameNS(SHOWPLAN_NS, "NoJoinPredicate").length > 0) {
          findings.push({ level: "amber", text: s.msgWarnNoJoinPredicate.replace("{op}", op.label).replace("{id}", op.nodeId) });
        }
        var spills = w.getElementsByTagNameNS(SHOWPLAN_NS, "SpillToTempDb");
        if (spills.length > 0) {
          findings.push({ level: "amber", text: s.msgWarnSpill.replace("{op}", op.label).replace("{id}", op.nodeId) });
        }
        var converts = w.getElementsByTagNameNS(SHOWPLAN_NS, "PlanAffectingConvert");
        for (var ci = 0; ci < converts.length; ci++) {
          findings.push({
            level: "amber",
            text: s.msgWarnConvert.replace("{op}", op.label).replace("{id}", op.nodeId).replace("{expr}", converts[ci].getAttribute("Expression") || "?")
          });
        }
        var noStats = w.getElementsByTagNameNS(SHOWPLAN_NS, "ColumnsWithNoStatistics");
        if (noStats.length > 0) {
          var colRefs = noStats[0].getElementsByTagNameNS(SHOWPLAN_NS, "ColumnReference");
          var names = [];
          for (var ni = 0; ni < colRefs.length; ni++) names.push(colRefs[ni].getAttribute("Column") || "?");
          findings.push({ level: "accent", text: s.msgWarnNoStats.replace("{op}", op.label).replace("{id}", op.nodeId).replace("{cols}", names.join(", ")) });
        }
      }

      if (op.estimateRows !== null && op.actualRows !== null && op.estimateRows > 0 && op.actualRows > 0) {
        var ratio = op.actualRows / op.estimateRows;
        var invRatio = op.estimateRows / op.actualRows;
        var worst = Math.max(ratio, invRatio);
        if (worst >= 10) {
          findings.push({
            level: "accent",
            text: s.msgSkewHigh
              .replace("{op}", op.label).replace("{id}", op.nodeId)
              .replace("{est}", Math.round(op.estimateRows).toLocaleString())
              .replace("{act}", Math.round(op.actualRows).toLocaleString())
              .replace("{ratio}", Math.round(worst).toLocaleString())
          });
        }
      }

      if ((op.logicalOp === "Key Lookup" || op.physicalOp === "RID Lookup") && op.actualExecutions !== null && op.actualExecutions > 100) {
        findings.push({
          level: "amber",
          text: s.msgLookup.replace("{op}", op.label).replace("{id}", op.nodeId).replace("{count}", Math.round(op.actualExecutions).toLocaleString())
        });
      }
    });

    plan.missingIndexGroups.forEach(function (mi) {
      findings.push({
        level: "accent",
        text: s.msgMissingIndex.replace("{table}", mi.table).replace("{impact}", Math.round(mi.impact)).replace("{cols}", mi.cols)
      });
    });

    if (plan.dop > 1) {
      var topOp = plan.operators.filter(function (o) { return o.isTopLevel; })[0] || plan.operators[0];
      if (topOp && topOp.actualRows !== null && topOp.actualRows < 100) {
        findings.push({
          level: "amber",
          text: s.msgParallelSmall.replace("{dop}", plan.dop).replace("{rows}", Math.round(topOp.actualRows).toLocaleString())
        });
      }
    }

    return findings;
  }

  // ---- rendering -----------------------------------------------------

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function renderResults(root, plan, s) {
    var out = root.querySelector(".epa-results");
    if (!plan) {
      out.innerHTML = '<p class="epa-empty">' + s.noMatch + "</p>";
      return null;
    }

    var html = "";
    html += '<h3 class="epa-op-title">' + s.opSummary + "</h3>";
    html += '<div class="epa-table-wrap"><table class="epa-table"><thead><tr><th>' +
      s.opCol + "</th><th>" + s.estCol + "</th><th>" + s.actCol + "</th></tr></thead><tbody>";
    plan.operators.forEach(function (op) {
      var est = op.estimateRows !== null ? Math.round(op.estimateRows).toLocaleString() : "—";
      var act = op.actualRows !== null ? Math.round(op.actualRows).toLocaleString() : "—";
      html += "<tr><td>" + escapeHtml(op.label) + " <span class=\"epa-node-id\">#" + escapeHtml(op.nodeId) + "</span></td><td>" + est + "</td><td>" + act + "</td></tr>";
    });
    html += "</tbody></table></div>";

    var findings = buildFindings(plan, s);
    html += '<h3 class="epa-findings-title">' + s.findings + "</h3>";
    if (findings.length === 0) {
      html += '<p class="epa-empty">' + s.noFindings + "</p>";
    } else {
      html += '<ul class="epa-findings">';
      findings.forEach(function (f) {
        html += '<li class="pill pill-' + f.level + '">' + escapeHtml(f.text) + "</li>";
      });
      html += "</ul>";
    }

    out.innerHTML = html;
    return { plan: plan, findings: findings };
  }

  function resultsToPlainText(result, s) {
    var lines = [];
    result.plan.operators.forEach(function (op) {
      lines.push(op.label + " #" + op.nodeId + "\t" + (op.estimateRows !== null ? Math.round(op.estimateRows) : "—") + "\t" + (op.actualRows !== null ? Math.round(op.actualRows) : "—"));
    });
    if (result.findings.length) {
      lines.push("");
      result.findings.forEach(function (f) { lines.push("- " + f.text); });
    }
    return lines.join("\n");
  }

  // ---- language switching ----------------------------------------------

  function applyLangText(root, lang) {
    var s = STRINGS[lang];

    var prose = root.querySelectorAll("[data-lang-text]");
    for (var i = 0; i < prose.length; i++) {
      prose[i].hidden = prose[i].getAttribute("data-lang-text") !== lang;
    }

    var links = root.querySelectorAll(".epa-lang-switch [data-set-lang]");
    for (var j = 0; j < links.length; j++) {
      links[j].classList.toggle("active", links[j].getAttribute("data-set-lang") === lang);
    }

    setText(root.querySelector(".epa-analyze"), s.analyze);
    setText(root.querySelector(".epa-clear"), s.clear);
    setText(root.querySelector(".epa-example"), s.example);
    setText(root.querySelector(".epa-copy"), s.copy);
    setText(root.querySelector(".epa-upload-label"), s.upload);

    var textarea = root.querySelector(".epa-input");
    if (textarea) textarea.placeholder = s.inputPlaceholder;

    var emailForm = root.querySelector(".epa-email-form");
    if (emailForm) {
      setText(emailForm.querySelector("button[type=submit]"), s.emailBtn);
      var emailInput = emailForm.querySelector(".epa-email-input");
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
    var textarea = root.querySelector(".epa-input");
    var fileInput = root.querySelector(".epa-file-input");
    applyLangText(root, lang);

    var langLinks = root.querySelectorAll(".epa-lang-switch [data-set-lang]");
    for (var li = 0; li < langLinks.length; li++) {
      langLinks[li].addEventListener("click", function (evt) {
        evt.preventDefault();
        lang = this.getAttribute("data-set-lang") === "en" ? "en" : "nl";
        s = STRINGS[lang];
        root.setAttribute("data-lang", lang);
        applyLangText(root, lang);
        if (textarea.value.trim()) runAnalyze();
      });
    }

    var analyzeBtn = root.querySelector(".epa-analyze");
    var clearBtn = root.querySelector(".epa-clear");
    var exampleBtn = root.querySelector(".epa-example");
    var copyBtn = root.querySelector(".epa-copy");
    var emailForm = root.querySelector(".epa-email-form");
    var emailInput = root.querySelector(".epa-email-input");
    var emailStatus = root.querySelector(".epa-email-status");
    var lastResult = null;
    var lastRaw = "";

    function runAnalyze() {
      var text = textarea.value;
      if (!text.trim()) {
        root.querySelector(".epa-results").innerHTML = '<p class="epa-empty">' + s.noInput + "</p>";
        lastResult = null;
        return;
      }
      lastRaw = text;
      lastResult = renderResults(root, parsePlan(text), s);
      if (emailForm) emailForm.classList.toggle("epa-hidden", !lastResult);
    }

    analyzeBtn.addEventListener("click", runAnalyze);
    clearBtn.addEventListener("click", function () {
      textarea.value = "";
      root.querySelector(".epa-results").innerHTML = "";
      lastResult = null;
      if (emailForm) emailForm.classList.add("epa-hidden");
    });
    exampleBtn.addEventListener("click", function () {
      textarea.value = EXAMPLE;
      runAnalyze();
    });

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function () {
          textarea.value = String(reader.result);
          runAnalyze();
        };
        reader.readAsText(file);
      });
    }

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
        body.set("action", "dbaronald_plan_analyzer_email");
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
    var roots = document.querySelectorAll(".plan-analyzer");
    for (var i = 0; i < roots.length; i++) init(roots[i]);
  });
})();
