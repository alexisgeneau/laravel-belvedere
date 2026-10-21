const provided = window.Belvedere ?? {};
const path = String(provided.path ?? 'belvedere').replace(/^\/+|\/+$/g, '');

export default Object.freeze({
    path,
    basePath: path === '' ? '' : `/${path}`,
    refreshSeconds: Math.max(1, Number(provided.refreshSeconds) || 5),
    readOnly: Boolean(provided.readOnly),
    silenced: Boolean(provided.silenced),
});
