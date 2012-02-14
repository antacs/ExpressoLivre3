/*
 * Tine 2.0
 * 
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2007-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 */
Ext.ns('Tine.widgets.grid');

/**
 * @namespace   Tine.widgets.grid
 * @class       Tine.widgets.grid.FilterToolbar
 * @extends     Ext.Panel
 * 
 * <br>Usage:<br>
     <pre><code>
     tb = new Tine.widgets.grid.FilterToolbar({
         filterModels: [
            {label: 'Full Name', field: 'n_fn', defaultOperator: 'contains'},
            {label: 'Container', field: 'container_id', operatorRenderer: function() {...}, valueRenderer: function() {...}},
            {label: 'Contact', field: 'quicksearch'}
         ],
         defaultFilter: 'quicksearch',
         filters: [
            {field: 'n_fn', operator: 'contains', value: 'Smith'},
            {field: 'container_id', operator: 'equals', value: 4}
        ]
     });
    </code></pre>
 * @constructor
 * @param {Object} config
 */
Tine.widgets.grid.FilterToolbar = function(config) {
    Ext.apply(this, config);
    Tine.widgets.grid.FilterToolbar.superclass.constructor.call(this);
    
    // become filterPlugin
    Ext.applyIf(this, new Tine.widgets.grid.FilterPlugin());
};

/**
 * Filter registry
 * @type Object
 */
Tine.widgets.grid.FilterToolbar.FILTERS = {};

Ext.extend(Tine.widgets.grid.FilterToolbar, Ext.Panel, {
    
    /**
     * @cfg {Array} array of filter models (possible filters in this toolbar)
     */
    filterModels: null,
    
    /**
     * @cfg {String} fieldname of default filter
     */
    defaultFilter: null,
    
    /**
     * @cfg {Bool} allowSaving (defaults to false)
     */
    allowSaving: false,
    
    /**
     * @cfg {Bool} neverAllowSaving (defaults to false)
     */
    neverAllowSaving: false,

    /**
     * @cfg {Bool} showSearchButton (defaults to true)
     */
    showSearchButton: true,
    
    filterFieldWidth: 240,
    filterValueWidth: 200,
    
    /**
     * @cfg {String} row prefix (defaults to _('Show'))
     */
    rowPrefix: null,
    
    border: false,
    monitorResize: true,
    region: 'north',
    layout: 'fit',
    //split: true,
    
    record: Ext.data.Record.create([
        {name: 'field'},
        {name: 'operator'},
        {name: 'value'}
    ]),
    
    frowIdPrefix: 'tw-ftb-frowid-',
    
    /**
     * @private
     */
    initTemplates : function() {
        var ts = this.templates || {};
        if(!ts.master) {
            ts.master = new Ext.Template(
                '<div class="tw-filtertoolbar x-toolbar x-small-editor" hidefocus="true">',
                    '<table style="width: auto;" border="0" cellpadding="0" cellspacing="0">',
                         '{tbody}', 
                     '</table>',
                '</div>'
            );
        }
        if(!ts.filterrow){
            ts.filterrow = new Ext.Template(
                '<tr id="{id}" class="fw-ftb-frow">',
                    '<td class="tw-ftb-frow-pbutton"></td>',
                    '<td class="tw-ftb-frow-mbutton"></td>',
                    '<td class="tw-ftb-frow-prefix">{prefix}</td>',
                    '<td class="tw-ftb-frow-field" width="' + this.filterFieldWidth + 'px">{field}</td>',
                    '<td class="tw-ftb-frow-operator" width="90px" >{operator}</td>',
                    '<td class="tw-ftb-frow-value" width="' + this.filterValueWidth + 'px">{value}</td>',
                    '<td class="tw-ftb-frow-searchbutton"></td>',
                    //'<td class="tw-ftb-frow-deleteallfilters"></td>',
                    //'<td class="tw-ftb-frow-savefilterbutton"></td>',
                '</tr>'
            );
        }
        
        for(var k in ts){
            var t = ts[k];
            if(t && typeof t.compile == 'function' && !t.compiled){
                t.disableFormats = true;
                t.compile();
            }
        }

        this.templates = ts;
    },
    
    /**
     * @private
     */
    initActions: function() {
        this.actions = {
            addFilterRow: new Ext.Button({
                //disabled: true,
                tooltip: _('add new filter'),
                iconCls: 'action_addFilter',
                scope: this,
                handler: this.addFilter
            }),
            removeAllFilters: new Ext.Button({
                tooltip: _('reset all filters'),
                iconCls: 'action_delAllFilter',
                scope: this,
                handler: this.deleteAllFilters
            }),
            startSearch: new Ext.Button({
                text: _('start search'),
                iconCls: 'action_startFilter',
                scope: this,
                handler: function() {
                    this.onFiltertrigger();
                }
            }),
            saveFilter: new Ext.Button({
                tooltip: _('save as favorite'),
                iconCls: 'action_saveFilter',
                handler: this.onSaveFilter.createDelegate(this)
            })
        };
    },
    
    /**
     * @private
     */
    onRender: function(ct, position) {
        Tine.widgets.grid.FilterToolbar.superclass.onRender.call(this, ct, position);
        
        // only get app and enable saving if this.store is available (that is not the case in the activities panel)
        // at this point the plugins are initialised
        if (! this.app && this.store) {
            this.app = Tine.Tinebase.appMgr.get(this.store.proxy.recordClass.getMeta('appName'));
        }
        
        // automatically enable saving
        if (! this.neverAllowSaving && this.app && this.app.getMainScreen() && typeof this.app.getMainScreen().getWestPanel == 'function' && this.app.getMainScreen().getWestPanel().hasFavoritesPanel) {
            this.allowSaving = true;
        }
        
        // render static table
        this.renderTable();
        
        // render each filter row into table
        this.filterStore.each(function(filter) {
            this.renderFilterRow(filter);
        }, this);
        
        // render static action buttons
        for (action in this.actions) {
            this.actions[action].hidden = true;
            this.actions[action].render(this.el);
        }
        
        // wrap search button an set it always mouse-overed
        this.searchButtonWrap = this.actions.startSearch.getEl().wrap();
        this.searchButtonWrap.addClass('x-btn-over');
        
        // arrange static action buttons
        this.onFilterRowsChange();
    },
    
    /**
     * save filterset as persisten filter
     */
    onSaveFilter: function() {
        this.app.getMainScreen().getWestPanel().getFavoritesPanel().saveFilter();
    },
    
    /**
     * renders static table
     * @private
     */
    renderTable: function() {
        var ts = this.templates;
        var tbody = '';
        
        this.filterStore.each(function(filter){
            tbody += ts.filterrow.apply({
                id: this.frowIdPrefix + filter.id
            });
        }, this);
        
        this.tableEl = ts.master.overwrite(this.bwrap, {tbody: tbody}, true);
    },
    
    /**
     * renders the filter specific stuff of a single filter row
     * 
     * @param {Ext.data.Record} el representing a filter tr tag
     * @private
     */
    renderFilterRow: function(filter) {
        filter.formFields = {};
        var filterModel = this.getFilterModel(filter.get('field'));

        var fRow = this.el.child('tr[id='+ this.frowIdPrefix + filter.id + ']');
        
        // field
        filter.formFields.field = new Ext.form.ComboBox({
            filter: filter,
            width: this.filterFieldWidth,
            id: 'tw-ftb-frow-fieldcombo-' + filter.id,
            mode: 'local',
            lazyInit: false,
            emptyText: _('select a field'),
            forceSelection: true,
            typeAhead: true,
            triggerAction: 'all',
            store: this.fieldStore,
            displayField: 'label',
            valueField: 'field',
            value: filterModel.field,
            renderTo: fRow.child('td[class=tw-ftb-frow-field]'),
            validator: this.validateFilter.createDelegate(this)
        });
        filter.formFields.field.on('select', function(combo, newRecord, newKey) {
            if (combo.value != combo.filter.get('field')) {
                this.onFieldChange(combo.filter, combo.value);
            }
        }, this);
        
        // operator
        filter.formFields.operator = filterModel.operatorRenderer(filter, fRow.child('td[class=tw-ftb-frow-operator]'));
        
        // value
        filter.formFields.value = filterModel.valueRenderer(filter, fRow.child('td[class=tw-ftb-frow-value]'));
        
        filter.deleteRowButton = new Ext.Button({
            id: 'tw-ftb-frow-deletebutton-' + filter.id,
            tooltip: _('Delete this filter'),
            filter: filter,
            iconCls: 'action_delThisFilter',
            renderTo: fRow.child('td[class=tw-ftb-frow-mbutton]'),
            scope: this,
            handler: function(button) {
                this.deleteFilter(button.filter);
            }
        });
    },
    
    /**
     * validate if type ahead is in our filter store
     * @return {Bool}
     */
    validateFilter: function(value) {
        return this.fieldStore.query('label', value).getCount() != 0;
    },
    
    /**
     * @private
     */
    arrangeButtons: function() {
        var numFilters = this.filterStore.getCount();
        var firstId = this.filterStore.getAt(0).id;
        var lastId = this.filterStore.getAt(numFilters-1).id;
        
        this.filterStore.each(function(filter){
            var tr = this.el.child('tr[id='+ this.frowIdPrefix + filter.id + ']');
            
            // prefix
            tr.child('td[class=tw-ftb-frow-prefix]').dom.innerHTML = _('and');
            //filter.deleteRowButton.setVisible(filter.id != lastId);
                
            if (filter.id == lastId) {
                // move add filter button
                tr.child('td[class=tw-ftb-frow-pbutton]').insertFirst(this.actions.addFilterRow.getEl());
                this.actions.addFilterRow.show();
                // move start search button
                tr.child('td[class=tw-ftb-frow-searchbutton]').insertFirst(this.searchButtonWrap);
                if (this.showSearchButton) {
                    this.actions.startSearch.show();
                }
                // move delete all filters
                // tr.child('td[class=tw-ftb-frow-deleteallfilters]').insertFirst(this.actions.removeAllFilters.getEl());
                this.actions.removeAllFilters.setVisible(numFilters > 1);
                // move save filter button
                // tr.child('td[class=tw-ftb-frow-savefilterbutton]').insertFirst(this.actions.saveFilter.getEl());
                this.actions.saveFilter.setVisible(this.allowSaving && numFilters > 1);
            }
            
            if (filter.id == firstId) {
                tr.child('td[class=tw-ftb-frow-prefix]').dom.innerHTML = this.rowPrefix;
                
                // hack for the save/delete all btns which are now in the first row
                //if (Ext.isSafari) {
                    this.actions.removeAllFilters.getEl().applyStyles('float: left');
                //} else {
                //    this.actions.saveFilter.getEl().applyStyles('display: inline');
                //    this.actions.removeAllFilters.getEl().applyStyles('display: inline');
                //}
                
                tr.child('td[class=tw-ftb-frow-searchbutton]').insertFirst(this.actions.saveFilter.getEl());
                tr.child('td[class=tw-ftb-frow-searchbutton]').insertFirst(this.actions.removeAllFilters.getEl());
                
                //tr.child('td[class=tw-ftb-frow-pmbutton]').insertFirst(this.actions.removeAllFilters.getEl());
                //this.actions.removeAllFilters.setVisible(numFilters > 1);
            }
        }, this);
    },
    
    doLayout: function() {
        if (typeof this.layout.layout == 'function') {
            Tine.widgets.grid.FilterToolbar.superclass.doLayout.apply(this, arguments);
        }
        
        if (this.rendered) {
            this.arrangeButtons();
            
            this.filterStore.each(function(filter){
                for (var formItemName in filter.formFields) {
                    if (filter.formFields[formItemName] && typeof filter.formFields[formItemName].syncSize == 'function') {
                        filter.formFields[formItemName].setWidth(filter.formFields[formItemName].width);
                        if (filter.formFields[formItemName].wrap) {
                            filter.formFields[formItemName].wrap.setWidth(filter.formFields[formItemName].width);
                        }
                        filter.formFields[formItemName].syncSize();
                    }
                }
            }, this);
        }
    },
    
    /**
     * called  when a filter action is to be triggered (start new search)
     * @private
     */
    onFiltertrigger: function() {
        if (! this.supressEvents) {
            this.onFilterChange();            
        }
    },
    
    /**
     * called on field change of a filter row
     * @private
     */
    onFieldChange: function(filter, newField) {
        var oldOperator = filter.formFields.operator.getValue();
        var oldValue    = filter.formFields.value.getValue();
        
        // only use old operator/value for textfields
        var f = filter.formFields.value;
        if (typeof f.selectText != 'function' || typeof f.doQuery == 'function') {
            oldValue = '';
        }
        
        // NOTE: removeMode got introduced on ext3.1 but is not docuemented
        //       'childonly' is no ext mode, we just need something other than 'container'
        filter.formFields.operator.removeMode = 'childsonly';
        filter.formFields.value.removeMode = 'childsonly';
        
        filter.formFields.operator.destroy();
        filter.formFields.value.destroy();
        
        var filterModel = this.getFilterModel(newField);
        var fRow = this.el.child('tr[id='+ this.frowIdPrefix + filter.id + ']');
        
        var opEl = fRow.child('td[class=tw-ftb-frow-operator]');
        var valEl = fRow.child('td[class=tw-ftb-frow-value]');
        
        filter.set('field', newField);
        filter.set('operator', '');
        filter.set('value', '');
        
        filter.formFields.operator = filterModel.operatorRenderer(filter, opEl);
        filter.formFields.value = filterModel.valueRenderer(filter, valEl);
        
        // only use old operator/value for textfields
        var f = filter.formFields.value;
        if (oldValue && typeof f.selectText == 'function' && typeof f.doQuery != 'function') {
            var o = filter.formFields.operator;
            
            if (typeof o.findRecord == 'function') {
                if (typeof o.setValue == 'function' && o.findRecord(o.valueField, oldOperator)) {
                    o.setValue(oldOperator);
                }
                filter.formFields.value.setValue(oldValue);
                filter.formFields.value.selectText.defer(50, filter.formFields.value);
            }
        }
    },
    
    /**
     * @private
     */
    initComponent: function() {
        Tine.widgets.grid.FilterToolbar.superclass.initComponent.call(this);
        
        this.on('show', function() {
            this.doLayout();
        }, this);
        
        if (this.rowPrefix === null) { 
            this.rowPrefix = _('Show');
        }
        
        this.initTemplates();
        this.initActions();
        
        // init filters
        if (this.filters.length < 1) {
            this.filters = [{field: this.defaultFilter}];
        }
        this.filterStore = new Ext.data.JsonStore({
            fields: this.record,
            data: this.filters
        });

        // init filter models
        this.filterModelMap = {};
        var filtersFields = [];
        
        for (var i=0; i<this.filterModels.length; i++) {
            var config = this.filterModels[i];
            var fm = this.createFilterModel(config);
            
            if (fm.isForeignFilter) {
                fm.field = fm.ownField + ':' + fm.foreignField;
            }
            
            this.filterModelMap[fm.field] = fm;
            filtersFields.push(fm);
            
            fm.on('filtertrigger', this.onFiltertrigger, this);
            
            // handle subfilters "inline" at the moment
            if (typeof fm.getSubFilters == 'function') {
                var subfilters = fm.getSubFilters();
                Ext.each(subfilters, function(sfm) {
                    sfm.isSubfilter = true;
                    
                    sfm.field = fm.ownField + ':' + sfm.field;
                    sfm.label = fm.label + ' - ' + sfm.label;
                    
                    this.filterModelMap[sfm.field] = sfm;
                    filtersFields.push(sfm);
                    sfm.on('filtertrigger', this.onFiltertrigger, this);
                }, this);
            }
        }
        
        // init filter selection
        this.fieldStore = new Ext.data.JsonStore({
            fields: ['field', 'label'],
            data: filtersFields
        });
    },
    
    /**
     * called when a filter row gets added/deleted
     * @private
     */
    onFilterRowsChange: function() {
        if (! this.supressEvents) {
            this.ownerCt.layout.layout();
        }
        this.doLayout();
    },
    
    createFilterModel: function(config) {
        if (config.isFilterModel) {
            return config;
        }
        
        // push this filtertoolbar in config
        config.ftb = this;
        
        if (config.filtertype) {
            // filter from reg
            return new Tine.widgets.grid.FilterToolbar.FILTERS[config.filtertype](config);
        } else {
            return new Tine.widgets.grid.FilterModel(config);
        }
    },
    
    /**
     * returns filterModel
     * 
     * @param {String} fieldName
     * @return {Tine.widgets.grid.FilterModel}
     */
    getFilterModel: function(fieldName) {
        return this.filterModelMap[fieldName];   
    },
    
    /**
     * adds a new filer row
     */
    addFilter: function(filter) {
        if (! filter || arguments[1]) {
            filter = new this.record({
                field: this.defaultFilter
            });
        }
        this.filterStore.add(filter);
        
        var fRow = this.templates.filterrow.insertAfter(this.el.child('tr[class=fw-ftb-frow]:last'),{
            id: 'tw-ftb-frowid-' + filter.id
        }, true);
        
        this.renderFilterRow(filter);
        
        this.onFilterRowsChange();
        
        /*
        if (!this.supressEvents) {
            this.onFiltertrigger();
        }
        */
        return filter;
    },
    
    /**
     * resets a filter
     * @param {Ext.Record} filter to reset
     */
    resetFilter: function(filter) {
        
    },
    
    /**
     * deletes a filter
     * @param {Ext.Record} filter to delete
     */
    deleteFilter: function(filter) {
        var fRow = this.el.child('tr[id=tw-ftb-frowid-' + filter.id + ']');
        //var isLast = this.filterStore.getAt(this.filterStore.getCount()-1).id == filter.id;
        var isLast = this.filterStore.getCount() == 1;
        this.filterStore.remove(this.filterStore.getById(filter.id));
        filter.formFields.field.destroy();
        filter.formFields.operator.destroy();
        filter.formFields.value.destroy();        
        
        if (isLast) {
            // add a new first row
            var firstFilter = this.addFilter();
            
            // save buttons somewhere
        	for (action in this.actions) {
	            this.actions[action].hide();
	            this.el.insertFirst(action == 'startSearch' ? this.searchButtonWrap : this.actions[action].getEl());
	        }
        }
        fRow.remove();
        
        this.onFilterRowsChange();
        
        if (!this.supressEvents) {
            this.onFiltertrigger();
        }
    },
    
    /**
     * deletes all filters
     */
    deleteAllFilters: function() {
        this.supressEvents = true;
        
        this.filterStore.each(function(filter) {
            this.deleteFilter(filter);
        },this);
        
        this.supressEvents = false;
        this.onFiltertrigger();
        this.onFilterRowsChange();
    },
    
    getValue: function() {
        var filters = [];
        var foreignFilters = {};
        this.filterStore.each(function(filter) {
            
            var line = {};
            for (var formfield in filter.formFields) {
                line[formfield] = filter.formFields[formfield].getValue();
            }
            
            // fill data with filter record data in case form field not exist (not rendered)
            filter.fields.each(function(field)  {
                var name = field.name;
                if (! line.hasOwnProperty(name)) {
                    line[name] = filter.get(name);
                }
            }, this);
            
            if (line.field && line.field.match(/:/)) {
                var parts = line.field.split(':');
                
                if (parts[0] == 'customfield') {
                    // customfield handling
                    filters.push({field: 'customfield', operator: line.operator, value: {cfId: parts[1], value: line.value}});
                } else {
                    // subfilter handling
                    var ownField = parts[0];
                    var foreignField = parts[1];
                    
                    line.field = foreignField;
                    foreignFilters[ownField] = foreignFilters[ownField] || [];
                    foreignFilters[ownField].push(line);
                }
                
            } else {
                filters.push(line);
            }
        }, this);
        
        for (var ownField in foreignFilters) {
            if (foreignFilters.hasOwnProperty(ownField)) {
                filters.push({field: ownField, operator: 'AND', value: foreignFilters[ownField]});
            }
        }
        
        return filters;
    },
    
    setValue: function(filters) {
        this.supressEvents = true;
        
        var oldFilterCount = this.filterStore.getCount();
        var skipFilter = [];
        
        var filterData, filter, existingFilterPos, existingFilter;
        
        for (var i=0; i<filters.length; i++) {
            filterData = filters[i];
            
            if (filterData.operator == 'AND' || filterData.operator == 'OR') {
                // subfilter handling
                var subFilters = filterData.value;
                for (var j=subFilters.length -1; j>=0; j--) {
                    subFilters[j].field = filterData.field + ':' + subFilters[j].field;
                    filters.splice(i+1, 0, subFilters[j]);
                }
            } else if (filterData.value && filterData.value.cfId) {
                // custom fields handling
                filters[i].field = filterData.field + ':' + filterData.value.cfId;
                filters[i].value = filterData.value.value;
            }
        }
        
        for (var i=0; i<filters.length; i++) {
            filterData = filters[i];
            filterData.filterValueWidth = this.filterValueWidth;
            
            if (this.filterModelMap[filterData.field]) {
                filter = new this.record(filterData);
                
                // check if this filter is already in our store
                existingFilterPos = this.filterStore.find('field', filterData.field);
                existingFilter = existingFilterPos >= 0 ? this.filterStore.getAt(existingFilterPos) : null;
                
                // we can't detect resolved records, sorry ;-(
                if (existingFilter && existingFilter.formFields.operator.getValue() == filter.get('operator') && existingFilter.formFields.value.getValue() == filter.get('value')) {
                    skipFilter.push(existingFilterPos);
                } else {
                    this.addFilter(filter);
                }
            }
        }
        
        for (var i=oldFilterCount-1; i>=0; i--) {
            if (skipFilter.indexOf(i) < 0) {
                this.deleteFilter(this.filterStore.getAt(i));
            }
        }
        
        this.supressEvents = false;
        this.onFilterRowsChange();
        
    },
    
    /**
     * gets filter data of all filter plugins
     * 
     * NOTE: As we can't find all filter plugins directly we need a litte hack 
     *       to get their data
     *       
     *       We register ourselve as latest beforeload.
     *       In the options.filter we have the filters then.
     */
    getAllFilterData: function() {
        this.store.on('beforeload', this.storeOnBeforeload, this);
        this.store.load();
        this.store.un('beforeload', this.storeOnBeforeload, this);
        
        return this.allFilterData;
    },
    
    storeOnBeforeload: function(store, options) {
        this.allFilterData = options.params.filter;
        this.store.fireEvent('exception');
        return false;
    }
    
});

Ext.reg('tinewidgetsfiltertoolbar', Tine.widgets.grid.FilterToolbar);
