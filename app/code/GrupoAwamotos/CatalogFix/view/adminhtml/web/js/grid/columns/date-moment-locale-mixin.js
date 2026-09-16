/**
 * Evita moment.defineLocale em locale já existente (warning no console do admin)
 * e formata a data a partir do valor cru da linha — não do texto já formatado
 * pelo Magento_Ui/js/grid/columns/date (moment.utc('24/08/2026 20:17') é inválido em pt_BR).
 */
define([
    'moment',
    'mageUtils',
    'underscore',
    'moment-timezone-with-data'
], function (moment, utils, _) {
    'use strict';

    var configuredLocales = {};

    return function (DateColumn) {
        return DateColumn.extend({
            /**
             * @param {Object} value
             * @param {String} [format]
             * @returns {String}
             */
            getLabel: function (value, format) {
                var date,
                    locale,
                    config,
                    raw;

                if (this.storeLocale !== undefined) {
                    locale = this.storeLocale;
                    config = utils.extend({}, this.calendarConfig);

                    if (!configuredLocales[locale]) {
                        if (moment.locales().indexOf(locale) !== -1) {
                            moment.updateLocale(locale, config);
                        } else {
                            moment.defineLocale(locale, config);
                        }
                        configuredLocales[locale] = true;
                    }
                    moment.locale(locale);
                }

                raw = value && value[this.index] !== undefined && value[this.index] !== null
                    ? value[this.index]
                    : '';

                if (raw === '' || raw === '0000-00-00 00:00:00') {
                    return '';
                }

                date = moment.utc(raw);

                if (!_.isUndefined(this.timezone) && moment.tz.zone(this.timezone) !== null) {
                    date = date.tz(this.timezone);
                }

                return date.isValid() ?
                    date.format(format || this.dateFormat) :
                    '';
            }
        });
    };
});
