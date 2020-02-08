/**
 * Settings JS
 * @uses vue.js
 * @uses vue-tabs.js
 * @uses jquery.js
 * @author amekusa
 */
(function ($) {

	/**
	 * @class
	 */
	function MappingsManager() {
		this.items = []
	}
	MappingsManager.prototype = {
		isEmpty: function () {
			return !this.items.length
		},
		hasAnyModified: function () {
			for (var item of this.items) {
				if (item.isModified()) return true
			}
			return false
		},
		getById: function (Id) {
			for (var item of this.items) {
				if (item.fields.id == Id) return item
			}
			return null
		},
		clear: function () {
			this.items.splice(0, this.items.length)
				// NOTE: This is how we should clear an array which is supposed to be reactive.
				//       If we once replaced its instance with another one, we lose its reactiveness.
		},
		newDraft: function (Index) {
			var map = new Mapping()
			this.add(map, Index)
			return map
		},
		erase: function (Map) {
			if (Map.isBusy()) {
				console.error("A delete attempt was rejected because the resource is busy")
				return
			}
			if (Map.isDraft()) { // If the map is draft, immediately remove it from the mappings array
				for (var i = 0; i < this.items.length; i++) {
					if (this.items[i] !== Map) continue
					this.remove(i)
					return $.Deferred().resolve()
				}
				console.error("Unexpected situation")
				return $.Deferred().reject()
			}
			Map.state = 'DELETING'
			var me = this
			return $.ajax(restUrl('/maps/' + Map.fields.id), { // Deletes a mapping from DB
				method: 'DELETE',
				beforeSend: restNonce

			}).always(function () {
				Map.setIdle()

			}).done(function (response) {
				return me.removeById(Map.fields.id)

			}).fail(function (e) {
				console.error("Couldn't delete a mapping", e)
			})
		},
		add: function (Map, To) {
			var index = To === undefined ? this.items.length : parseInt(To)
			this.items.splice(index, 0, Map)
		},
		remove: function (Index) {
			this.items.splice(Index, 1)
		},
		removeById: function (Id) {
			for (var i = 0; i < this.items.length; i++) {
				if (this.items[i].fields.id == Id) return this.remove(i)
			}
		},
		load: function () {
			var me = this
			return $.ajax(restUrl('/maps/'), { // Retrieve all the mappings from DB
				method: 'GET',
				dataType: 'json',
				beforeSend: restNonce

			}).done(function (response) { // Add the mappings to this manager
				me.clear()
				for (var item of response) {
					var iMap = new Mapping()
					iMap.setFields(item)
					iMap.updateSavedFields()
					me.add(iMap)
				}

			}).fail(function (e) { // Request failed
				console.error("Couldn't fetch the mappings", e)
			})
		},
		save: function (Map) {
			if (Map.isBusy()) {
				console.error("A save attempt was rejected because the resource is busy")
				return
			}
			var method, param
			if (Map.isDraft()) {
				method = 'POST' // Create
				param = 0
				// Find index to insert
				for (var item of this.items) {
					if (!item.isDraft()) param++
					if (item === Map) break
				}
			} else {
				method = 'PUT' // Update
				param = Map.fields.id // ID to update
			}
			Map.state = 'SAVING'
			var me = this
			return $.ajax(restUrl('/maps/' + param), { // Creates or Updates a mapping on DB
				method: method,
				dataType: 'json',
				contentType: 'application/json', // This prevents boolean values from turning into strings
				data: Map.serialize(),
				beforeSend: restNonce

			}).always(function () {
				Map.setIdle()

			}).done(function (response) {
				Map.setFields(response)
				Map.updateSavedFields()

			}).fail(function (e) {
				console.error("Couldn't save a mapping", e)
			})
		},
		serialize: function () {
			return JSON.stringify(this.toJson())
		},
		toJson: function () {
			var r = []
			for (var item of this.items) r.push(item.fields)
			return r
		}
	}

	/**
	 * @class
	 */
	function Mapping() {
		this.fields = {}
		this.savedFields = {}
		this.state = '' // IDLE / SAVING / DELETING

		this.resetFields()
		this.setIdle()
	}
	Mapping.prototype = {
		isDraft: function () {
			return this.fields.id <= 0;
		},
		isModified: function () {
			if (this.isDraft()) return true
			// Compare the current field values to the saved ones
			for (var i in this.fields) {
				if (!(i in this.savedFields)) return true
				if (this.fields[i] !== this.savedFields[i]) return true
			}
			return false
		},
		isBusy: function () {
			return this.state != 'IDLE'
		},
		setIdle: function () {
			this.state = 'IDLE'
			return this
		},
		setFields: function (Fields) {
			for (var i in this.fields) {
				if (!i in Fields) continue
				this.fields[i] = Fields[i]
			}
			return this
		},
		resetFields: function () {
			schema = $_WPLR_THEMEX_SETTINGS.maps.schema
			for (var i in schema) this.fields[i] = schema[i].default
			return this
		},
		updateSavedFields: function () {
			for (var i in this.fields) this.savedFields[i] = this.fields[i]
		},
		serialize: function () {
			return JSON.stringify({
				fields: this.fields
			})
		}
	}

	function restUrl(Route) {
		var base = $_WPLR_THEMEX_SETTINGS.maps.api.url
		return base + Route
	}

	function restNonce(Xhr) {
		Xhr.setRequestHeader('X-WP-Nonce', $_WPLR_THEMEX_SETTINGS.maps.api.nonce)
	}

	/**
	 * The entry point
	 */
	function main() {
		Vue.use(VueTabs); // Activate vue-tabs module

		var maps = new MappingsManager()

		// Load mappings
		maps.load().always(function () {

			// Mappings component
			new Vue({
				el: '#mappings',
				data: {
					NAME_LENGTH_MAX: 24,
					mappings: maps,
					schema: $_WPLR_THEMEX_SETTINGS.maps.schema
				},
				methods: {
					isHierarchical: function (PostType) {
						if (!PostType) return false
						if (!(PostType in this.schema.posttype.options)) return false
						return this.schema.posttype.options[PostType].hierarchical
					},
					showsPostMeta: function (Map) {
						var values = [
							'ids_in_post_meta',
							'ids_in_post_meta_imploded',
							'urls_in_post_meta',
							'ids_as_string_in_post_meta'
						]
						return ($.inArray(Map.fields.posttype_mode, values) >= 0)
					},
					isModified: function (Map) {
						return Map.isModified()
					},
					isBusy: function (Map) {
						return Map.isBusy()
					},
					isSaving: function (Map) {
						return Map.state == 'SAVING'
					},
					isDeleting: function (Map) {
						return Map.state == 'DELETING'
					},
					taxonomies: function (PostType) {
						var r = []
						if (!PostType) return r
						for (var i in this.schema.taxonomy.options) {
							var iOpt = this.schema.taxonomy.options[i]
							// if (iOpt.posttype.indexOf(PostType) >= 0) r.push(iOpt) // IE8 incompatible
							if ($.inArray(PostType, iOpt.posttype) >= 0) r.push(iOpt)
						}
						return r
					},
					newDraft: function (Index) {
						this.mappings.newDraft(Index)
					},
					erase: function (Map) {
						this.mappings.erase(Map)
					},
					save: function (Map) {
						this.mappings.save(Map)
					},
					tabTitle: function (Index) {
						var index = parseInt(Index)
						var map = this.mappings.items[index]
						var prefix = '#' + (index + 1) + ' '
						var suffix = map.isModified() ? ' *' : ''
						return prefix + (map.fields.name || 'New Mapping') + suffix
					}
				}
			})

			// Fires on leaving or reloading the page without saving changes
			window.addEventListener('beforeunload', function (ev) {
				if (!maps.hasAnyModified()) return // No change was made
				ev.preventDefault()
				ev.returnValue = 'Discard changes?' // Prompt
			})
		})
	}

	$(document).on('ready', main)
})(jQuery)
