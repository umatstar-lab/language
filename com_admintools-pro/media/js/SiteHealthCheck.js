/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */
"use strict";

window.admintools = window.admintools || {};

/**
 * Site Health Check.
 *
 * The results are fetched asynchronously, like Joomla's quick icons do. The Site Health Check page renders the HTML the
 * server returns; the Control Panel only shows the number of problems as a badge on its Site Health Check button.
 */
window.admintools.SiteHealthCheck = window.admintools.SiteHealthCheck || {
    /** Bootstrap colour per severity, most severe first. Keep in sync with Severity::bootstrapColour(). */
    severityColours: {
        critical: "danger",
        high:     "warning",
        medium:   "primary",
        low:      "secondary"
    },

    /** Are the passed and ignored checks currently shown? */
    showAll: false,

    /** localStorage key remembering whether the passed and ignored checks are shown */
    storageKey: "admintools.SiteHealthCheck.showAll",

    init()
    {
        const options = Joomla.getOptions("admintools.SiteHealthCheck") || {};

        if (!options.resultsUrl)
        {
            return;
        }

        if (options.summaryOnly)
        {
            this.loadBadge(options.resultsUrl);

            return;
        }

        this.loadPage(options.resultsUrl);
    },

    fetchResults(url)
    {
        // POST the anti-CSRF token: in the query string it would end up in access and proxy logs.
        const data = new FormData();

        data.append(Joomla.getOptions("csrf.token"), "1");

        return fetch(url, {
            method:      "POST",
            body:        data,
            credentials: "same-origin",
            headers:     {"Accept": "application/json"}
        })
            .then(response =>
            {
                if (!response.ok)
                {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json();
            })
            .then(data =>
            {
                if (!data || !data.success)
                {
                    throw new Error((data && data.error) ? data.error : "Invalid response");
                }

                return data;
            });
    },

    loadBadge(url)
    {
        const badge = document.getElementById("admintoolsSiteHealthCheckBadge");

        if (!badge)
        {
            return;
        }

        this.fetchResults(url)
            .then(data =>
            {
                const summary  = data.summary || {};
                const problems = parseInt(summary.problems || 0, 10);
                let colour     = "success";

                Object.keys(this.severityColours).some(severity =>
                {
                    if (parseInt((summary.severity || {})[severity] || 0, 10) > 0)
                    {
                        colour = this.severityColours[severity];

                        return true;
                    }

                    return false;
                });

                badge.textContent = problems > 0 ? String(problems) : "✓";
                badge.classList.add(`text-bg-${colour}`);
                badge.classList.remove("d-none");
            })
            .catch(() =>
            {
                // The badge is a nicety; stay silent on failure.
            });
    },

    loadPage(url)
    {
        const container = document.getElementById("admintoolsSiteHealthCheck");

        if (!container)
        {
            return;
        }

        container.addEventListener("click", event => this.onClick(event));

        this.fetchResults(url)
            .then(data =>
            {
                // Server-rendered by the akeeba.admintools.sitehealthcheck.results layout, which escapes its output.
                container.innerHTML = data.html;

                this.syncInlineHelp(container);

                this.setShowAll(this.getStoredShowAll());
            })
            .catch(error =>
            {
                const alert     = document.createElement("div");
                alert.className = "alert alert-danger";
                alert.textContent = Joomla.Text._("COM_ADMINTOOLS_SITEHEALTHCHECK_ERR_LOAD_FAILED")
                    .replace("%s", error.message);

                container.replaceChildren(alert);
            });
    },

    onClick(event)
    {
        const toggle = event.target.closest("[data-shc-toggle]");

        if (toggle)
        {
            event.preventDefault();

            const showAll = toggle.getAttribute("aria-pressed") !== "true";

            this.setShowAll(showAll);
            this.storeShowAll(showAll);

            return;
        }

        const button = event.target.closest("[data-shc-task]");

        if (!button || button.disabled)
        {
            return;
        }

        event.preventDefault();

        const confirmation = button.dataset.shcConfirm || "";

        if (confirmation !== "" && !window.confirm(confirmation))
        {
            return;
        }

        // Fixes which end the session (or change the whole page) need a full page load anyway.
        if (button.hasAttribute("data-shc-reload") || typeof window.fetch !== "function")
        {
            this.submitForm(button);

            return;
        }

        this.applyInPlace(button);
    },

    /**
     * Submit the page's form, the classic way: the controller redirects back to the page.
     */
    submitForm(button)
    {
        const form = document.getElementById("adminForm");

        form.elements.check_id.value = button.dataset.shcCheck || "";
        form.elements.fix_id.value   = button.dataset.shcFix || "";
        form.elements.item_key.value = button.dataset.shcItem || "";

        Joomla.submitform(button.dataset.shcTask, form);
    },

    /**
     * Apply a fix, or (un)ignore a check, over fetch; then update that check's card and the summary in place.
     */
    applyInPlace(button)
    {
        const form    = document.getElementById("adminForm");
        const card    = button.closest("[data-shc-card]");
        const buttons = card ? card.querySelectorAll("[data-shc-task]") : [button];
        const data    = new FormData();
        const url     = form.getAttribute("action") + (form.getAttribute("action").includes("?") ? "&" : "?")
                        + "format=raw";

        data.append("task", button.dataset.shcTask);
        data.append("check_id", button.dataset.shcCheck || "");
        data.append("fix_id", button.dataset.shcFix || "");
        data.append("item_key", button.dataset.shcItem || "");
        data.append(Joomla.getOptions("csrf.token"), "1");

        buttons.forEach(element => element.disabled = true);

        const spinner     = document.createElement("span");
        spinner.className = "spinner-border spinner-border-sm ms-1";
        spinner.setAttribute("role", "status");
        spinner.setAttribute("aria-hidden", "true");
        button.appendChild(spinner);

        fetch(url, {
            method:      "POST",
            body:        data,
            credentials: "same-origin",
            headers:     {"Accept": "application/json"}
        })
            .then(response => response.json())
            .then(result =>
            {
                if (result.reload)
                {
                    window.location.reload();

                    return;
                }

                if (result.message && typeof Joomla.renderMessages === "function")
                {
                    Joomla.renderMessages({[result.success ? "success" : "error"]: [result.message]});
                }

                this.updateInPlace(card, result);
            })
            .catch(() =>
            {
                // Not JSON (e.g. an expired token, or the Master Password prompt): let the page handle it.
                this.submitForm(button);
            })
            .finally(() =>
            {
                if (button.isConnected)
                {
                    spinner.remove();
                    buttons.forEach(element => element.disabled = false);
                }
            });
    },

    /**
     * Replace a check's card and the summary with the fresh ones the server rendered, keeping the page's state.
     */
    updateInPlace(card, result)
    {
        const container = document.getElementById("admintoolsSiteHealthCheck");
        const summary   = container.querySelector("[data-shc-summary]");

        // Server-rendered by the Site Health Check layouts, which escape their output.
        if (card && typeof result.checkHtml === "string")
        {
            if (result.checkHtml === "")
            {
                card.remove();
            }
            else
            {
                card.outerHTML = result.checkHtml;
            }
        }

        if (summary && typeof result.summaryHtml === "string" && result.summaryHtml !== "")
        {
            summary.outerHTML = result.summaryHtml;
        }

        this.syncSections(container);
        this.syncInlineHelp(container);
        this.setShowAll(this.showAll);
    },

    /**
     * A section is secondary (hidden unless showing all checks) when all of its checks are.
     */
    syncSections(container)
    {
        container.querySelectorAll("[data-shc-section]").forEach(section =>
        {
            const cards        = Array.from(section.querySelectorAll("[data-shc-card]"));
            const allSecondary = cards.length > 0 && cards.every(card => card.hasAttribute("data-shc-secondary"));

            section.toggleAttribute("data-shc-secondary", allSecondary);

            if (!allSecondary)
            {
                section.classList.remove("d-none");
            }
        });
    },

    /**
     * Joomla's Toggle Inline Help button toggles every div.hide-aware-inline-help on the page. Our help texts arrive
     * after the page has loaded, so give them the same state as the placeholder which was there from the start.
     */
    syncInlineHelp(container)
    {
        const placeholder = document.getElementById("admintoolsSiteHealthCheckHelpState");
        const helpShown   = placeholder !== null && !placeholder.classList.contains("d-none");

        container.querySelectorAll("div.hide-aware-inline-help")
                 .forEach(element => element.classList.toggle("d-none", !helpShown));
    },

    setShowAll(showAll)
    {
        this.showAll = showAll;

        document.querySelectorAll("#admintoolsSiteHealthCheck [data-shc-secondary]")
                .forEach(element => element.classList.toggle("d-none", !showAll));

        document.querySelectorAll("#admintoolsSiteHealthCheck [data-shc-toggle]").forEach(toggle =>
        {
            toggle.setAttribute("aria-pressed", showAll ? "true" : "false");
            toggle.querySelector("[data-shc-toggle-show]").classList.toggle("d-none", showAll);
            toggle.querySelector("[data-shc-toggle-hide]").classList.toggle("d-none", !showAll);
        });
    },

    getStoredShowAll()
    {
        try
        {
            return window.localStorage.getItem(this.storageKey) === "1";
        }
        catch (e)
        {
            return false;
        }
    },

    storeShowAll(showAll)
    {
        try
        {
            window.localStorage.setItem(this.storageKey, showAll ? "1" : "0");
        }
        catch (e)
        {
            // Private browsing, blocked storage… Not a problem.
        }
    }
};

window.addEventListener("DOMContentLoaded", () => window.admintools.SiteHealthCheck.init());
