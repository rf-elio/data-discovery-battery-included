import template from './test-api-connection.html.twig';
import './test-api-connection.scss';

const {Component, Mixin} = Shopware;

Component.register('elio-battery-included-test-api-connection', {
        template,
        mixins: [
            Mixin.getByName('notification'),
            Mixin.getByName('sw-inline-snippet'),
        ],
        data() {
            return {
                isLoading: false,
                isSaveSuccessful: false,
            };
        },

        methods: {
            init() {
                this.$super('init');
                this.showNotification([]);
            },
            async onClick() {
                const me = this;
                this.isLoading = true;
                const httpClient = Shopware.Service('syncService').httpClient;
                const url = '/_action/elio-battery-included/api-connection-test';
                const basicHeaders = {
                    Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    'Content-Type': 'application/json'
                };

                httpClient
                    .get(url, {
                        headers: basicHeaders
                    })
                    .then((response) => {
                        me.showNotificationWithResults(response.data.testResults);
                    })
                    .catch((error) => {
                        if (error.response.data.hasOwnProperty('testResults')) {
                            me.showNotificationWithResults(error.response.data.testResults);
                        } else {
                            me.showFailureNotification();
                        }
                    })
                    .finally(() => {
                        this.isSaveSuccessful = true;
                        this.isLoading = false;
                    });
            },
            showFailureNotification() {
                this.createNotificationError({
                    title: this.$tc('elio-battery-included.configuration.testConnection.fail'),
                    message: this.$tc('elio-battery-included.configuration.testConnection.helpText')
                });
            },
            showNotificationWithResults(testResults) {
                const me = this;
                let hasError = false, hasWarning = false;
                let resultString = Object.keys(testResults)
                    .map(function (key) {
                        let salesChannelName = key;
                        if (key === '*') {
                            salesChannelName = me.$tc('sw-sales-channel-switch.labelDefaultOption');
                        }

                        const restResult = testResults[key];
                        if (restResult === 'fail') {
                            hasError = true;
                        } else if (restResult === 'configuration_needed') {
                            hasWarning = true;
                        }
                        return ' - ' + salesChannelName + ': ' + me.$tc('elio-battery-included.configuration.testConnection.testResult.' + testResults[key])
                    })
                    .join('<br/>');

                const message = this.$tc('elio-battery-included.configuration.testConnection.helpText')
                    + ' '
                    + this.$tc('elio-battery-included.configuration.testConnection.results', 0, {results: '<br/>' + resultString});

                if (hasError) {
                    this.createNotificationError({
                        title: this.$tc('elio-battery-included.configuration.testConnection.fail'),
                        message: message
                    });
                } else if (hasWarning) {
                    this.createNotificationWarning({
                        title: this.$tc('elio-battery-included.configuration.testConnection.success'),
                        message: message
                    });
                } else {
                    this.createNotificationSuccess({
                        title: this.$tc('elio-battery-included.configuration.testConnection.success'),
                        message: message
                    });
                }
            }
        }
    },
);
