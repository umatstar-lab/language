/**
 * @package   admintools
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

((window, document) =>
{
    var interval = null;
    var tries    = 0;

    const goTo = (url) =>
    {
        if (!url)
        {
            return;
        }

        window.location = url;
    };

    const armButtons = () =>
    {
        tries++;

        const group      = document.getElementById("plgSystemAdmintoolsDLFOffGroup");
        const offButton  = document.getElementById("plgSystemAdmintoolsDLFOff");
        const delButton  = document.getElementById("plgSystemAdmintoolsDLFOffDelete");
        const hideButton = document.getElementById("plgSystemAdmintoolsDLFHide");

        if (!group && !hideButton)
        {
            // Give up after about five seconds
            if (tries >= 50)
            {
                clearInterval(interval);
                interval = null;
            }

            return;
        }

        clearInterval(interval);
        interval = null;

        const options = Joomla.getOptions('plg_system_admintools')?.debugLogFileReminder;

        if (group && options?.offURL && options?.offDeleteURL)
        {
            group.classList.remove('d-none');

            offButton?.addEventListener("click", () => goTo(options.offURL));
            delButton?.addEventListener("click", () => goTo(options.offDeleteURL));
        }

        if (hideButton && options?.hideURL)
        {
            hideButton.classList.remove('d-none');

            hideButton.addEventListener("click", () => goTo(options.hideURL));
        }
    };

    interval = setInterval(armButtons, 100);
})(window, document);
