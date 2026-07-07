/* dbaronald.com — interactions: typing terminal, reveal, skills,
   GitHub repos widget, SQL tip rotator, language switch, mobile nav */

(function () {
  "use strict";

  var lang = document.documentElement.lang === "nl" ? "nl" : "en";

  /* ---------- language switch: remember preference ---------- */
  var langSwitch = document.querySelector(".lang-switch");
  if (langSwitch) {
    langSwitch.addEventListener("click", function () {
      var target = langSwitch.getAttribute("data-lang");
      try { localStorage.setItem("dbaronald-lang", target); } catch (e) { /* private mode */ }
    });
  }

  /* ---------- mobile nav ---------- */
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.querySelector(".site-nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      nav.classList.toggle("open");
    });
    nav.addEventListener("click", function (e) {
      if (e.target.tagName === "A") nav.classList.remove("open");
    });
  }

  /* ---------- reveal on scroll + skill bars ---------- */
  var observed = document.querySelectorAll(".reveal, .skill-bar");
  if ("IntersectionObserver" in window && observed.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("in-view");
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.18 });
    observed.forEach(function (el) { io.observe(el); });
  } else {
    observed.forEach(function (el) { el.classList.add("in-view"); });
  }

  /* ---------- terminal typing animation ---------- */
  var term = document.getElementById("terminal-type");
  if (term) {
    var query = term.getAttribute("data-query") || "";
    var result = term.getAttribute("data-result") || "";
    var i = 0;
    term.textContent = "";
    var typeNext = function () {
      if (i <= query.length) {
        term.textContent = query.slice(0, i);
        i++;
        setTimeout(typeNext, 28 + Math.random() * 40);
      } else {
        var res = document.getElementById("terminal-result");
        if (res) {
          res.innerHTML = result;
          res.style.opacity = "1";
        }
      }
    };
    if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      term.textContent = query;
      var res2 = document.getElementById("terminal-result");
      if (res2) { res2.innerHTML = result; res2.style.opacity = "1"; }
    } else {
      setTimeout(typeNext, 500);
    }
  }

  /* ---------- SQL tip of the day ---------- */
  var tips = {
    nl: [
      "Kijk in een executieplan eerst naar de dikste pijlen: daar stromen de meeste rijen.",
      "Een Key Lookup bij veel rijen? Overweeg een covering index met INCLUDE-kolommen.",
      "SET STATISTICS IO ON vertelt je meer over I/O dan de geschatte kosten in het plan.",
      "Verouderde statistieken zijn oorzaak nummer één van slechte cardinality estimates.",
      "Een index op (A, B) helpt niet bij een WHERE alleen op B — kolomvolgorde telt.",
      "Scalar UDF's in een SELECT dwingen rij-voor-rij verwerking af. Vermijd ze in grote queries.",
      "sys.dm_db_index_usage_stats laat zien welke indexen nooit gebruikt worden.",
      "Impliciete conversies (CONVERT_IMPLICIT) maken een index vaak onbruikbaar.",
      "Gebruik Ola Hallengren's maintenance solution voor backups, integrity checks en index maintenance.",
      "Een Clustered Index Scan is niet per se slecht — bij kleine tabellen is het juist optimaal.",
      "Parameter sniffing: één plan voor alle parameterwaarden. Soms goed, soms desastreus.",
      "Query Store aanzetten kost weinig en geeft je een tijdmachine voor plan-regressies."
    ],
    en: [
      "In an execution plan, look at the thickest arrows first: that's where the rows flow.",
      "Key Lookup with many rows? Consider a covering index with INCLUDE columns.",
      "SET STATISTICS IO ON tells you more about I/O than the estimated costs in the plan.",
      "Stale statistics are the number one cause of bad cardinality estimates.",
      "An index on (A, B) won't help a WHERE on B alone — column order matters.",
      "Scalar UDFs in a SELECT force row-by-row processing. Avoid them in large queries.",
      "sys.dm_db_index_usage_stats shows you which indexes are never used.",
      "Implicit conversions (CONVERT_IMPLICIT) often make an index unusable.",
      "Use Ola Hallengren's maintenance solution for backups, integrity checks and index maintenance.",
      "A Clustered Index Scan isn't necessarily bad — for small tables it's actually optimal.",
      "Parameter sniffing: one plan for all parameter values. Sometimes fine, sometimes disastrous.",
      "Enabling Query Store costs little and gives you a time machine for plan regressions."
    ]
  };

  var tipEl = document.getElementById("sql-tip-text");
  if (tipEl) {
    var list = tips[lang];
    // day-of-year based so the tip changes daily, same for every visitor
    var now = new Date();
    var day = Math.floor((now - new Date(now.getFullYear(), 0, 0)) / 864e5);
    tipEl.textContent = list[day % list.length];
  }

  /* ---------- GitHub repos widget ---------- */
  var ghBox = document.getElementById("gh-repos");
  if (ghBox) {
    var user = ghBox.getAttribute("data-user") || "ronaldgithub";
    fetch("https://api.github.com/users/" + user + "/repos?sort=updated&per_page=6")
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (repos) {
        repos = repos.filter(function (r) { return !r.fork; }).slice(0, 6);
        if (!repos.length) return;
        ghBox.innerHTML = "";
        repos.forEach(function (repo) {
          var a = document.createElement("a");
          a.className = "card";
          a.href = repo.html_url;
          a.target = "_blank";
          a.rel = "noopener";
          var desc = repo.description ||
            (lang === "nl" ? "Geen beschrijving" : "No description");
          var updated = new Date(repo.pushed_at).toLocaleDateString(
            lang === "nl" ? "nl-NL" : "en-GB",
            { year: "numeric", month: "short", day: "numeric" });
          var h3 = document.createElement("h3");
          h3.textContent = repo.name;
          var p = document.createElement("p");
          p.textContent = desc;
          var meta = document.createElement("div");
          meta.className = "gh-meta";
          meta.textContent = (repo.language ? repo.language + "  ·  " : "") +
            "★ " + repo.stargazers_count + "  ·  " + updated;
          a.appendChild(h3); a.appendChild(p); a.appendChild(meta);
          ghBox.appendChild(a);
        });
      })
      .catch(function () { /* keep static fallback link */ });
  }

  /* ---------- footer year ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();
})();
