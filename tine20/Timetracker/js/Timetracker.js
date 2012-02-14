/*
 * Tine 2.0
 * 
 * @package     Timetracker
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2007-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 */
 
Ext.ns('Tine.Timetracker');

/**
 * @namespace   Tine.Timetracker
 * @class       Tine.Timetracker.Application
 * @extends     Tine.Tinebase.Application
 * Timetracker Application Object <br>
 * 
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 */
Tine.Timetracker.Application = Ext.extend(Tine.Tinebase.Application, {
    init: function() {
        Tine.Timetracker.Application.superclass.init.apply(this, arguments);
        
        Ext.ux.ItemRegistry.registerItem('Tine.widgets.grid.GridPanel.addButton', {
            text: this.i18n._('New Timesheet'), 
            iconCls: 'TimetrackerTimesheet',
            scope: this,
            handler: function() {
                var ms = this.getMainScreen(),
                    cp = ms.getCenterPanel('Timesheet');
                    
                cp.onEditInNewWindow.call(cp, {});
            }
        });
    }
});

/**
 * @namespace   Tine.Timetracker
 * @class       Tine.Timetracker.MainScreen
 * @extends     Tine.widgets.MainScreen
 * MainScreen of the Timetracker Application <br>
 * 
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * 
 * @constructor
 */
Tine.Timetracker.MainScreen = Ext.extend(Tine.widgets.MainScreen, {
    activeContentType: 'Timesheet',
    westPanelXType: 'tine.timetracker.treepanel'
});

/**
 * @namespace   Tine.Timetracker
 * @class       Tine.Timetracker.TreePanel
 * @extends     Tine.widgets.persistentfilter.PickerPanel
 * 
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * 
 * @constructor
 * @xtype       tine.timetracker.treepanel
 */
Tine.Timetracker.TreePanel = Ext.extend(Tine.widgets.persistentfilter.PickerPanel, {
    
    filter: [{field: 'model', operator: 'equals', value: 'Timetracker_Model_TimesheetFilter'}],
    
    // quick hack to get filter saving grid working
    //recordClass: Tine.Timetracker.Model.Timesheet,
    initComponent: function() {
        this.filterMountId = 'Timesheet';
        
        this.root = {
            id: 'root',
            leaf: false,
            expanded: true,
            children: [{
                text: this.app.i18n._('Timesheets'),
                id : 'Timesheet',
                iconCls: 'TimetrackerTimesheet',
                expanded: true,
                children: [{
                    text: this.app.i18n._('All Timesheets'),
                    id: 'alltimesheets',
                    leaf: true
                }]
            }]
        };
        
        if (Tine.Tinebase.common.hasRight('manage', 'Timetracker', 'timeaccounts')) {
            this.root.children.push({
                text: this.app.i18n._('Timeaccounts'),
                id: 'Timeaccount',
                iconCls: 'TimetrackerTimeaccount',
                expanded: true,
                children: [{
                    text: this.app.i18n._('All Timeaccounts'),
                    id: 'alltimeaccounts',
                    leaf: true
                }]
            });
        }
        
    	Tine.Timetracker.TreePanel.superclass.initComponent.call(this);
        
        this.on('click', function(node) {
            if (node.attributes.isPersistentFilter != true) {
                var contentType = node.getPath().split('/')[2];
                
                this.app.getMainScreen().activeContentType = contentType;
                this.app.getMainScreen().show();
            }
        }, this);
	},
    
    /**
     * @private
     */
    afterRender: function() {
        Tine.Timetracker.TreePanel.superclass.afterRender.call(this);
        var type = this.app.getMainScreen().activeContentType;

        this.expandPath('/root/' + type + '/alltimesheets');
        this.selectPath('/root/' + type + '/alltimesheets');
    },
    
    /**
     * load grid from saved filter
     */
    onFilterSelect: function() {
        this.app.getMainScreen().activeContentType = 'Timesheet';
        this.app.getMainScreen().show();
        
        this.supr().onFilterSelect.apply(this, arguments);
    },
    
    /**
     * returns a filter plugin to be used in a grid
     */
    getFilterPlugin: function() {
        if (!this.filterPlugin) {
            var scope = this;
            this.filterPlugin = new Tine.widgets.grid.FilterPlugin({});
        }
        
        return this.filterPlugin;
    },
    
    getFavoritesPanel: function() {
        return this;
    }
});

Ext.reg('tine.timetracker.treepanel', Tine.Timetracker.TreePanel);


/**
 * default timesheets backend
 */
Tine.Timetracker.timesheetBackend = new Tine.Tinebase.data.RecordProxy({
    appName: 'Timetracker',
    modelName: 'Timesheet',
    recordClass: Tine.Timetracker.Model.Timesheet
});

/**
 * default timeaccounts backend
 */
Tine.Timetracker.timeaccountBackend = new Tine.Tinebase.data.RecordProxy({
    appName: 'Timetracker',
    modelName: 'Timeaccount',
    recordClass: Tine.Timetracker.Model.Timeaccount
});
