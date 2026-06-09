(() => {
    console.log('[ABConvert] Running theme clear cache script 1.0.1')
    const currentThemeId = window.Shopify?.theme?.id || window.abconvertThemeId;
    let isRunningThemeTest = false;
    if (window.abconvertThemeTestDict) {
        const { themeIdMap } = window.abconvertThemeTestDict
        if (themeIdMap[Number(currentThemeId)]) {
            isRunningThemeTest = true;
        }
    }
    if (!isRunningThemeTest) {
        if (window.localStorage.getItem('abconvert-is-redirected-test-theme')) {
            window.localStorage.removeItem('abconvert-is-redirected-test-theme')
            //add query parameter preview_theme_id= to the url
            const url = new URLSearchParams(location.search);
            url.set("preview_theme_id", ''), (location.search = url.toString());
        }
    }
})()


