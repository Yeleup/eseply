import grapesjs from 'grapesjs';
import 'grapesjs/dist/css/grapes.min.css';

const FRAGMENT_KEYS = ['meters_table', 'logo', 'qr'];

function chipHtml(key, label) {
    return `<span class="rt-var" title="${label}">{{${key}}}</span>`;
}

window.initReceiptTemplateEditor = function (container, config, getState) {
    const editor = grapesjs.init({
        container,
        height: '640px',
        fromElement: false,
        storageManager: false,
        undoManager: true,
        i18n: {},
        canvas: {
            styles: [],
        },
        blockManager: {
            blocks: buildBlocks(config.variables),
        },
    });

    const state = getState();
    editor.setComponents(state.html || config.defaultHtml);
    editor.setStyle(state.css || config.defaultCss);

    return editor;
};

function buildBlocks(variables) {
    const blocks = [
        {
            id: 'rt-text',
            label: 'Текст',
            category: 'Элементы',
            content: '<p>Введите текст</p>',
        },
        {
            id: 'rt-heading',
            label: 'Заголовок',
            category: 'Элементы',
            content: '<h2>Заголовок</h2>',
        },
        {
            id: 'rt-columns',
            label: 'Две колонки',
            category: 'Элементы',
            content: '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div><p>Колонка 1</p></div><div><p>Колонка 2</p></div></div>',
        },
        {
            id: 'rt-divider',
            label: 'Разделитель',
            category: 'Элементы',
            content: '<hr>',
        },
    ];

    for (const variable of variables) {
        blocks.push({
            id: `rt-var-${variable.key}`,
            label: variable.label,
            category: FRAGMENT_KEYS.includes(variable.key) ? 'Фрагменты' : 'Переменные',
            content: FRAGMENT_KEYS.includes(variable.key)
                ? `<div>{{${variable.key}}}</div>`
                : chipHtml(variable.key, variable.label),
        });
    }

    return blocks;
}
