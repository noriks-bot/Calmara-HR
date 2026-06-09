function loadScript(URL, loaded = () => { }) {
  const element = document.createElement("script");
  element.src = URL;
  element.type = "text/javascript";
  element.onload = function () {
    loaded();
  };
  document.getElementsByTagName("head")[0].appendChild(element);
}
const BUCKS_HOST = ((window || {}).location || {}).origin || '';
const bucksScriptURL = `${BUCKS_HOST}/apps/buckscc/sdk.min.js`;

loadScript(bucksScriptURL, () => {
  // Script loaded callback
  console.log("Script loaded!");
});

