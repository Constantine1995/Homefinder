import Aura from '@primeuix/themes/aura';
import { definePreset, palette } from '@primeuix/themes';

/**
 * The class on <html> that switches PrimeVue to the dark scheme: the cabinet has only the night theme.
 */
export const DARK_MODE_CLASS = 'night';

/**
 * Aura reshaped by the night theme of app.css: deep violet surfaces, light text, the violet accent with white text,
 * pill-shaped fields and buttons. The surface scale runs from the text (0) to the page (950).
 */
export const HomefinderPreset = definePreset(Aura, {
    primitive: {
        borderRadius: {
            none: '0',
            xs: '2px',
            sm: '6px',
            md: '12px',
            lg: '16px',
            xl: '24px',
        },
    },
    semantic: {
        primary: palette('#7c4dff'),
        focusRing: {
            width: '2px',
            style: 'solid',
            color: '#d9c8ff',
            offset: '2px',
        },
        formField: {
            borderRadius: '46px',
        },
        content: {
            borderRadius: '24px',
        },
        colorScheme: {
            dark: {
                surface: {
                    0: '#ffffff',
                    50: '#f4f2fb',
                    100: '#e4e1ee',
                    200: '#cbc7da',
                    300: '#a7a3b8',
                    400: '#8a86a0',
                    500: '#6d6985',
                    600: '#4a4560',
                    700: '#2c2840',
                    800: '#2a2640',
                    900: '#1b1828',
                    950: '#12101c',
                },
                primary: {
                    color: '#7c4dff',
                    contrastColor: '#ffffff',
                    hoverColor: '#4a1fb8',
                    activeColor: '#4a1fb8',
                },
                highlight: {
                    background: '#7c4dff',
                    focusBackground: '#4a1fb8',
                    color: '#ffffff',
                    focusColor: '#ffffff',
                },
                text: {
                    color: '#f4f2fb',
                    hoverColor: '#ffffff',
                    mutedColor: '#a7a3b8',
                    hoverMutedColor: '#cbc7da',
                },
                content: {
                    background: '#1b1828',
                    hoverBackground: '#2a2640',
                    borderColor: '#2c2840',
                    color: '#f4f2fb',
                },
                formField: {
                    background: '#12101c',
                    filledBackground: '#1b1828',
                    borderColor: '#2c2840',
                    hoverBorderColor: '#4a4560',
                    focusBorderColor: '#7c4dff',
                    color: '#f4f2fb',
                    placeholderColor: '#a7a3b8',
                },
                overlay: {
                    popover: { background: '#1b1828', borderColor: '#2c2840', color: '#f4f2fb' },
                    select: { background: '#1b1828', borderColor: '#2c2840', color: '#f4f2fb' },
                    modal: { background: '#1b1828', borderColor: '#2c2840', color: '#f4f2fb' },
                },
            },
        },
    },
    components: {
        button: {
            root: {
                borderRadius: '46px',
            },
        },
        datatable: {
            colorScheme: {
                dark: {
                    headerCell: { background: '#1b1828', color: '#a7a3b8', borderColor: '#2c2840' },
                    row: { background: '#1b1828', hoverBackground: '#2a2640', color: '#f4f2fb' },
                    bodyCell: { borderColor: '#2c2840' },
                    footerCell: { background: '#1b1828', borderColor: '#2c2840' },
                },
            },
        },
    },
});

/**
 * Russian texts of the PrimeVue components the cabinet uses.
 */
export const russianLocale = {
    emptyMessage: 'Нет вариантов',
    emptyFilterMessage: 'Ничего не найдено',
    emptySelectionMessage: 'Ничего не выбрано',
    emptySearchMessage: 'Ничего не найдено',
    selectionMessage: 'Выбрано: {0}',
    searchMessage: 'Найдено: {0}',
    selectAll: 'Выбрать всё',
    unselectAll: 'Снять выбор',
    clear: 'Очистить',
    accept: 'Да',
    reject: 'Нет',
    aria: {
        close: 'Закрыть',
        previousPageLabel: 'Предыдущая страница',
        nextPageLabel: 'Следующая страница',
        firstPageLabel: 'Первая страница',
        lastPageLabel: 'Последняя страница',
        rowsPerPageLabel: 'Строк на странице',
        pageLabel: 'Страница {page}',
    },
};
