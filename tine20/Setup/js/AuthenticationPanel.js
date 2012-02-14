/*
 * Tine 2.0
 * 
 * @package     Setup
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 *
 */
 
Ext.ns('Tine', 'Tine.Setup');
 
/**
 * Setup Authentication Manager
 * 
 * @namespace   Tine.Setup
 * @class       Tine.Setup.AuthenticationPanel
 * @extends     Tine.Tinebase.widgets.form.ConfigPanel
 * 
 * <p>Authentication Panel</p>
 * <p><pre>
 * TODO         move to next step after install?
 * TODO         make default is valid mechanism with 'allowEmpty' work
 * TODO         add port for ldap hosts
 * </pre></p>
 * 
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * 
 * @param       {Object} config
 * @constructor
 * Create a new Tine.Setup.AuthenticationPanel
 */
Tine.Setup.AuthenticationPanel = Ext.extend(Tine.Tinebase.widgets.form.ConfigPanel, {
    
    /**
     * @property idPrefix DOM Id prefix
     * @type String
     */
    idPrefix: null,
    
    /**
     * authProviderPrefix DOM Id prefix
     * 
     * @property authProviderIdPrefix
     * @type String
     */
    authProviderIdPrefix: null,
    
    /**
     * accountsStorageIdPrefix DOM Id prefix
     * 
     * @property accountsStorageIdPrefix
     * @type String
     */
    accountsStorageIdPrefix: null,
    
    /**
     * combo box containing the authentication backend selection
     * 
     * @property authenticationBackendCombo
     * @type Ext.form.ComboBox 
     */
    authenticationBackendCombo: null,

    /**
     * combo box containing the accounts storage selection
     * 
     * @property accountsStorageCombo
     * @type Ext.form.ComboBox
     */
    accountsStorageCombo: null,
    
    /**
     * The currently active accounts storage backend
     * 
     * @property originalAccountsStorage
     * @type String
     */
    originalAccountsStorage: null,

    /**
     * @private
     * panel cfg
     */
    saveMethod: 'Setup.saveAuthentication',
    registryKey: 'authenticationData',
    
    /**
     * @private
     */
    initComponent: function() {
        this.idPrefix                   = Ext.id();
        this.authProviderIdPrefix       = this.idPrefix + '-authProvider-',
        this.accountsStorageIdPrefix    = this.idPrefix + '-accountsStorage-',
        this.originalAccountsStorage    = (Tine.Setup.registry.get(this.registryKey).accounts) 
            ? Tine.Setup.registry.get(this.registryKey).accounts.backend
            : 'Sql';
        
        Tine.Setup.AuthenticationPanel.superclass.initComponent.call(this);
    },
    
    /**
     * Change card layout depending on selected combo box entry
     */
    onChangeAuthProvider: function() {
        var authProvider = this.authenticationBackendCombo.getValue();
        
        var cardLayout = Ext.getCmp(this.authProviderIdPrefix + 'CardLayout').getLayout();
        cardLayout.setActiveItem(this.authProviderIdPrefix + authProvider);
    },
    
    /**
     * Change card layout depending on selected combo box entry
     */
    onChangeAccountsStorage: function() {
        var AccountsStorage = this.accountsStorageCombo.getValue();

        if (AccountsStorage == 'Ldap' && AccountsStorage != this.originalAccountsStorage) {
          Ext.Msg.confirm(this.app.i18n._('Delete all existing users and groups'), this.app.i18n._('Switching from SQL to LDAP will delete all existing User Accounts, Groups and Roles. Do you really want to switch the accounts storage backend to LDAP ?'), function(confirmbtn, value) {
                if (confirmbtn == 'yes') {
                    this.doOnChangeAccountsStorage(AccountsStorage);
                } else {
                  this.accountsStorageCombo.setValue(this.originalAccountsStorage);
                }
            }, this);
        } else {
          this.doOnChangeAccountsStorage(AccountsStorage);
        }
    },
    
    /**
     * Change card layout depending on selected combo box entry
     */
    doOnChangeAccountsStorage: function(AccountsStorage) {
        var cardLayout = Ext.getCmp(this.accountsStorageIdPrefix + 'CardLayout').getLayout();
        cardLayout.setActiveItem(this.accountsStorageIdPrefix + AccountsStorage);
        this.originalAccountsStorage = AccountsStorage;
    },
    
    /**
     * @private
     */
    onRender: function(ct, position) {
        Tine.Setup.AuthenticationPanel.superclass.onRender.call(this, ct, position);
        
        this.onChangeAuthProvider.defer(250, this);
        this.onChangeAccountsStorage.defer(250, this);
    },
    
        
    /**
     * transforms form data into a config object
     * 
     * @hack   smuggle termsAccept in 
     * @return {Object} configData
     */
    form2config: function() {
        configData = this.supr().form2config.call(this);
        configData.acceptedTermsVersion = Tine.Setup.registry.get('acceptedTermsVersion');
        return configData;
    },
    
   /**
     * returns config manager form
     * 
     * @private
     * @return {Array} items
     */
    getFormItems: function() {
        var setupRequired = Tine.Setup.registry.get('setupRequired');
        
        this.authenticationBackendCombo = new Ext.form.ComboBox({
            width: 300,
                listWidth: 300,
                mode: 'local',
                forceSelection: true,
                allowEmpty: false,
                triggerAction: 'all',
                selectOnFocus:true,
                store: [['Sql', 'Sql'], ['Ldap','Ldap'], ['Imap', 'IMAP']],
                name: 'authentication_backend',
                fieldLabel: this.app.i18n._('Backend'),
                value: 'Sql',
                listeners: {
                    scope: this,
                    change: this.onChangeAuthProvider,
                    select: this.onChangeAuthProvider
                },
                tabIndex: 1
            });
            
       this.accountsStorageCombo = new Ext.form.ComboBox({
                xtype: 'combo',
                width: 300,
                listWidth: 300,
                mode: 'local',
                forceSelection: true,
                allowEmpty: false,
                triggerAction: 'all',
                selectOnFocus:true,
                store: [['Sql', 'Sql'], ['Ldap','Ldap']],
                name: 'accounts_backend',
                fieldLabel: this.app.i18n._('Backend'),
                value: 'Sql',
                listeners: {
                    scope: this,
                    change: this.onChangeAccountsStorage,
                    select: this.onChangeAccountsStorage
                }
            });
        
        return [{
            xtype:'fieldset',
            collapsible: true,
            collapsed: !setupRequired,
            autoHeight: true,
            title: this.app.i18n._('Initial Admin User'),
            items: [{
                layout: 'form',
                autoHeight: 'auto',
                border: false,
                defaults: {
                    width: 300,
                    xtype: 'textfield',
                    inputType: 'password'
                },
                items: [{
                    inputType: 'text',
                    name: 'authentication_Sql_adminLoginName',
                    fieldLabel: this.app.i18n._('Initial admin login name'),
                    disabled: !setupRequired,
                    tabIndex: 2
                }, {
                    name: 'authentication_Sql_adminPassword',
                    fieldLabel: this.app.i18n._('Initial admin Password'),
                    disabled: !setupRequired,
                    tabIndex: 3
                }, {
                    name: 'authentication_Sql_adminPasswordConfirmation',
                    fieldLabel: this.app.i18n._('Password confirmation'),
                    disabled: !setupRequired,
                    tabIndex: 4
                }]
            }]
        }, {
            xtype:'fieldset',
            collapsible: false,
            autoHeight:true,
            title: this.app.i18n._('Authentication provider'),
            items: [
                this.authenticationBackendCombo,
                {
                id: this.authProviderIdPrefix + 'CardLayout',
                layout: 'card',
                activeItem: this.authProviderIdPrefix + 'Sql',
                border: false,
                defaults: {
                    border: false
                },
                items: [{
                    id: this.authProviderIdPrefix + 'Sql',
                    layout: 'form',
                    autoHeight: 'auto',
                    defaults: {
                        width: 300,
                        xtype: 'textfield'
                    },
                    items: [{
                        xtype: 'combo',
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'authentication_Sql_tryUsernameSplit',
                        fieldLabel: this.app.i18n._('Try to split username'),
                        value: '1'
                    }, {
                        xtype: 'combo',
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['2', 'ACCTNAME_FORM_USERNAME'], ['3','ACCTNAME_FORM_BACKSLASH'], ['4','ACCTNAME_FORM_PRINCIPAL']],
                        name: 'authentication_Sql_accountCanonicalForm',
                        fieldLabel: this.app.i18n._('Account canonical form'),
                        value: '2'
                    }, {
                        name: 'authentication_Sql_accountDomainName',
                        fieldLabel: this.app.i18n._('Account domain name '),
                        tabIndex: 7
                    }, {
                        name: 'authentication_Sql_accountDomainNameShort',
                        fieldLabel: this.app.i18n._('Account domain short name'),
                        tabIndex: 8
                    } ]
                }, {
                    id: this.authProviderIdPrefix + 'Ldap',
                    layout: 'form',
                    autoHeight: 'auto',
                    defaults: {
                        width: 300,
                        xtype: 'textfield'
                    },
                    items: [{
                        inputType: 'text',
                        name: 'authentication_Ldap_host',
                        fieldLabel: this.app.i18n._('Host')
                    }/*, {
                        inputType: 'text',
                        name: 'authentication_Ldap_port',
                        fieldLabel: this.app.i18n._('Port')
                    }*/, {
                        inputType: 'text',
                        name: 'authentication_Ldap_username',
                        fieldLabel: this.app.i18n._('Login name')
                    }, {
                        name: 'authentication_Ldap_password',
                        fieldLabel: this.app.i18n._('Password'),
                        inputType: 'password'
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'authentication_Ldap_bindRequiresDn',
                        fieldLabel: this.app.i18n._('Bind requires DN'),
                        value: '1'
                    }, {
                        name: 'authentication_Ldap_baseDn',
                        fieldLabel: this.app.i18n._('Base DN')
                    }, {
                        name: 'authentication_Ldap_accountFilterFormat',
                        fieldLabel: this.app.i18n._('Search filter')
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['2', 'ACCTNAME_FORM_USERNAME'], ['3','ACCTNAME_FORM_BACKSLASH'], ['4','ACCTNAME_FORM_PRINCIPAL']],
                        name: 'authentication_Ldap_accountCanonicalForm',
                        fieldLabel: this.app.i18n._('Account canonical form'),
                        value: '2'
                    }, {
                        name: 'authentication_Ldap_accountDomainName',
                        fieldLabel: this.app.i18n._('Account domain name ')
                    }, {
                        name: 'authentication_Ldap_accountDomainNameShort',
                        fieldLabel: this.app.i18n._('Account domain short name')
                    }]
                }, {
                    id: this.authProviderIdPrefix + 'Imap',
                    layout: 'form',
                    autoHeight: 'auto',
                    defaults: {
                        width: 300,
                        xtype: 'textfield'
                    },
                    items: [{
                        name: 'authentication_Imap_host',
                        fieldLabel: this.app.i18n._('Hostname')
                    }, {
                        name: 'authentication_Imap_port',
                        fieldLabel: this.app.i18n._('Port'),
                        xtype: 'numberfield'
                    }, {
                        fieldLabel: this.app.i18n._('Secure Connection'),
                        name: 'authentication_Imap_ssl',
                        typeAhead     : false,
                        triggerAction : 'all',
                        lazyRender    : true,
                        editable      : false,
                        mode          : 'local',
                        value: 'none',
                        xtype: 'combo',
                        listWidth: 300,
                        store: [
                            ['none', this.app.i18n._('None')],
                            ['tls',  this.app.i18n._('TLS')],
                            ['ssl',  this.app.i18n._('SSL')]
                        ]
                    }, {
                        name: 'authentication_Imap_domain',
                        fieldLabel: this.app.i18n._('Append domain to login name')
                    }
//                    {
//                        inputType: 'text',
//                        xtype: 'combo',
//                        width: 300,
//                        listWidth: 300,
//                        mode: 'local',
//                        forceSelection: true,
//                        allowEmpty: false,
//                        triggerAction: 'all',
//                        selectOnFocus:true,
//                        store: [['1', 'Yes'], ['0','No']],
//                        name: 'authentication_Sql_tryUsernameSplit',
//                        fieldLabel: this.app.i18n._('Try to split username'),
//                        value: '1'
//                    }, {
//                        inputType: 'text',
//                        xtype: 'combo',
//                        width: 300,
//                        listWidth: 300,
//                        mode: 'local',
//                        forceSelection: true,
//                        allowEmpty: false,
//                        triggerAction: 'all',
//                        selectOnFocus:true,
//                        store: [['2', 'ACCTNAME_FORM_USERNAME'], ['3','ACCTNAME_FORM_BACKSLASH'], ['4','ACCTNAME_FORM_PRINCIPAL']],
//                        name: 'authentication_Sql_accountCanonicalForm',
//                        fieldLabel: this.app.i18n._('Account canonical form'),
//                        value: '2'
//                    }, {
//                        name: 'authentication_Sql_accountDomainName',
//                        fieldLabel: this.app.i18n._('Account domain name '),
//                        inputType: 'text',
//                        tabIndex: 7
//                    }, {
//                        name: 'authentication_Sql_accountDomainNameShort',
//                        fieldLabel: this.app.i18n._('Account domain short name'),
//                        inputType: 'text',
//                        tabIndex: 8
//                    } 
                    ]
                }]
            } ]
          }, {
            xtype:'fieldset',
            collapsible: false,
            autoHeight:true,
            title: this.app.i18n._('Accounts storage'),
            items: [
                this.accountsStorageCombo,
                {
                id: this.accountsStorageIdPrefix + 'CardLayout',
                layout: 'card',
                activeItem: this.accountsStorageIdPrefix + 'Sql',
                border: false,
                defaults: {
                    border: false
                },
                items: [ {
                    id: this.accountsStorageIdPrefix + 'Sql',
                    layout: 'form',
                    autoHeight: 'auto',
                    defaults: {
                        width: 300,
                        xtype: 'textfield'
                    },
                    items: [ {
                        name: 'accounts_Sql_defaultUserGroupName',
                        fieldLabel: this.app.i18n._('Default user group name')
                        //allowEmpty: false
                    }, {
                        name: 'accounts_Sql_defaultAdminGroupName',
                        fieldLabel: this.app.i18n._('Default admin group name')
                        //allowEmpty: false
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'accounts_Sql_changepw',
                        fieldLabel: this.app.i18n._('User can change password'),
                        value: '0'
                    } ]
                }, {
                    id: this.accountsStorageIdPrefix + 'Ldap',
                    layout: 'form',
                    autoHeight: 'auto',
                    defaults: {
                        width: 300,
                        xtype: 'textfield'
                    },
                    items: [{
                        inputType: 'text',
                        name: 'accounts_Ldap_host',
                        fieldLabel: this.app.i18n._('Host')
                    },
                    {
                        inputType: 'text',
                        name: 'accounts_Ldap_username',
                        fieldLabel: this.app.i18n._('Login name')
                    },{
                        name: 'accounts_Ldap_password',
                        fieldLabel: this.app.i18n._('Password'),
                        inputType: 'password'
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'accounts_Ldap_bindRequiresDn',
                        fieldLabel: this.app.i18n._('Bind requires DN'),
                        value: '1'
                    }, {
                        name: 'accounts_Ldap_userDn',
                        fieldLabel: this.app.i18n._('User DN')
                    }, {
                        name: 'accounts_Ldap_userFilter',
                        fieldLabel: this.app.i18n._('User Filter')
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'SEARCH_SCOPE_SUB'], ['2','SEARCH_SCOPE_ONE']],
                        name: 'accounts_Ldap_userSearchScope',
                        fieldLabel: this.app.i18n._('User Search Scope'),
                        value: '1'
                    }, {
                        name: 'accounts_Ldap_groupsDn',
                        fieldLabel: this.app.i18n._('Groups DN')
                    }, {
                        name: 'accounts_Ldap_groupFilter',
                        fieldLabel: this.app.i18n._('Group Filter')
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'SEARCH_SCOPE_SUB'], ['2','SEARCH_SCOPE_ONE']],
                        name: 'accounts_Ldap_groupSearchScope',
                        fieldLabel: this.app.i18n._('Group Search Scope'),
                        value: '1'
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['CRYPT', 'CRYPT'], ['SHA','SHA'], ['MD5','MD5']],
                        name: 'accounts_Ldap_pwEncType',
                        fieldLabel: this.app.i18n._('Password encoding'),
                        value: 'CRYPT'
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'accounts_Ldap_useRfc2307bis',
                        fieldLabel: this.app.i18n._('Use Rfc 2307 bis'),
                        value: '0'
                    }, {
                        name: 'accounts_Ldap_minUserId',
                        fieldLabel: this.app.i18n._('Min User Id')
                    }, {
                        name: 'accounts_Ldap_maxUserId',
                        fieldLabel: this.app.i18n._('Max User Id')
                    }, {
                        name: 'accounts_Ldap_minGroupId',
                        fieldLabel: this.app.i18n._('Min Group Id')
                    }, {
                        name: 'accounts_Ldap_maxGroupId',
                        fieldLabel: this.app.i18n._('Max Group Id')
                    }, {
                        name: 'accounts_Ldap_groupUUIDAttribute',
                        fieldLabel: this.app.i18n._('Group UUID Attribute name')
                    }, {
                        name: 'accounts_Ldap_userUUIDAttribute',
                        fieldLabel: this.app.i18n._('User UUID Attribute name')
                    }, {
                        name: 'accounts_Ldap_defaultUserGroupName',
                        fieldLabel: this.app.i18n._('Default user group name')
                    }, {
                        name: 'accounts_Ldap_defaultAdminGroupName',
                        fieldLabel: this.app.i18n._('Default admin group name')
                    }, {
                        xtype: 'combo',
                        width: 300,
                        listWidth: 300,
                        mode: 'local',
                        forceSelection: true,
                        allowEmpty: false,
                        triggerAction: 'all',
                        selectOnFocus:true,
                        store: [['1', 'Yes'], ['0','No']],
                        name: 'accounts_Ldap_changepw',
                        fieldLabel: this.app.i18n._('Allow user to change her password?'),
                        value: '0'
                    } ]
                }]
            } ]
          }, {
            xtype:'fieldset',
            collapsible: false,
            autoHeight:true,
            title: this.app.i18n._('Redirect Settings'),
            defaults: {
                width: 300,
                xtype: 'textfield'
            },
            items: [{
                inputType: 'text',
                name: 'redirectSettings_redirectUrl',
                fieldLabel: this.app.i18n._('Redirect Url (redirect to login screen if empty)')
            }, {
                xtype: 'combo',
                listWidth: 300,
                mode: 'local',
                forceSelection: true,
                allowEmpty: false,
                triggerAction: 'all',
                selectOnFocus:true,
                store: [['1', 'Yes'], ['0','No']],
                value: '0',
                name: 'redirectSettings_redirectAlways',
                fieldLabel: this.app.i18n._('Redirect Always (if No, only redirect after logout)')
            }, {
                xtype: 'combo',
                listWidth: 300,
                mode: 'local',
                forceSelection: true,
                allowEmpty: false,
                triggerAction: 'all',
                selectOnFocus:true,
                store: [['1', 'Yes'], ['0','No']],
                name: 'redirectSettings_redirectToReferrer',
                fieldLabel: this.app.i18n._('Redirect to referring site, if exists'),
                value: '0'
            } ]
          } ];
    },
    
    /**
     * applies registry state to this cmp
     */
    applyRegistryState: function() {
        this.action_saveConfig.setDisabled(false);
        
        if (Tine.Setup.registry.get('setupRequired')) {
            this.action_saveConfig.setText(this.app.i18n._('Save config and install'));
        } else {
            this.action_saveConfig.setText(this.app.i18n._('Save config'));
            this.getForm().findField('authentication_Sql_adminPassword').setDisabled(true);
            this.getForm().findField('authentication_Sql_adminPasswordConfirmation').setDisabled(true);
            this.getForm().findField('authentication_Sql_adminLoginName').setDisabled(true);
        }
    },
    
    /**
     * checks if form is valid
     * - password fields are equal
     * 
     * @return {Boolean}
     */
    isValid: function() {
        var form = this.getForm();

        var result = form.isValid();
        
        // check if passwords match
        if (this.authenticationBackendCombo.getValue() == 'Sql' && form.findField('authentication_Sql_adminPassword') 
            && form.findField('authentication_Sql_adminPassword').getValue() != form.findField('authentication_Sql_adminPasswordConfirmation').getValue()) 
        {
            form.markInvalid([{
                id: 'authentication_Sql_adminPasswordConfirmation',
                msg: this.app.i18n._("Passwords don't match")
            }]);
            result = false;
        }
        
        // check if initial username/passwords are set
        if (
            Tine.Setup.registry.get('setupRequired') 
            && form.findField('authentication_Sql_adminLoginName')
        ) {
            if (form.findField('authentication_Sql_adminLoginName').getValue() == '') {
                form.markInvalid([{
                    id: 'authentication_Sql_adminLoginName',
                    msg: this.app.i18n._("Should not be empty")
                }]);
                result = false;
            }
            if (form.findField('authentication_Sql_adminPassword').getValue() == '') {
                form.markInvalid([{
                    id: 'authentication_Sql_adminPassword',
                    msg: this.app.i18n._("Should not be empty")
                }]);
                form.markInvalid([{
                    id: 'authentication_Sql_adminPasswordConfirmation',
                    msg: this.app.i18n._("Should not be empty")
                }]);
                result = false;
            }
        }
        
        if (this.accountsStorageCombo.getValue() == 'Sql' && 
                form.findField('accounts_Sql_defaultUserGroupName') && form.findField('accounts_Sql_defaultUserGroupName').getValue() == ''
            ) {
            form.markInvalid([{
                id: 'accounts_Sql_defaultUserGroupName',
                msg: this.app.i18n._("Should not be empty")
            }]);
            result = false;
        }
        
        if (this.accountsStorageCombo.getValue() == 'Sql' && 
                form.findField('accounts_Sql_defaultAdminGroupName') && form.findField('accounts_Sql_defaultAdminGroupName').getValue() == ''
            ) {
            form.markInvalid([{
                id: 'accounts_Sql_defaultAdminGroupName',
                msg: this.app.i18n._("Should not be empty")
            }]);
            result = false;
        }
        
        return result;
    }
});
