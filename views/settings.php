<div class="wrap">
	<?php echo $wplr_admin->display_title( "Theme Assistant | WP/LR Sync" );  ?>
	<p><?php _e( "There is a tutorial about this extension:", 'wplr-sync' ) ?> <a target="_blank" href="https://meowapps.com/wplr-sync-post-types-extension/">Theme Assistant</a>.</p>

	<!-- Vue Component -->
	<div id="mappings">
		<div class="v-placeholder loading" v-cloak>
			<i class="spinner"></i> Loading…
		</div>
		<div v-if="mappings.isEmpty()" id="no-mappings" class="v-fadein" v-cloak>
			<button class="button button-primary" @click.prevent="newDraft(0)">Create New Mapping</button>
		</div>
		<div v-else class="v-fadein" v-cloak>
			<vue-tabs>
				<v-tab v-for="(mapping, i) in mappings.items" :key="mapping.id" :title="tabTitle(i)">
					<form @submit.prevent="save(mapping)">
						<div class="tab-content-header">
							<input
								type="text"
								name="name"
								:maxlength="NAME_LENGTH_MAX" :size="NAME_LENGTH_MAX"
								placeholder="Name this mapping (optional)"
								v-model="mapping.fields.name"
								:disabled="isBusy(mapping)"
							>
							<div class="buttons">
								<button v-if="isModified(mapping)" class="save button button-primary" :disabled="isBusy(mapping)" type="submit">
									<i v-if="isSaving(mapping)" class="dashicons dashicons-update awaiting"></i> Save Changes
								</button>
								<button v-else class="save button button-primary" disabled> Saved</button>
								<button class="add button button" @click.prevent="newDraft(i + 1)">
									<i class="dashicons dashicons-plus"></i> Add New Mapping
								</button>
								<button class="delete button" @click.prevent="erase(mapping)" :disabled="isBusy(mapping)">
									<i v-if="isDeleting(mapping)" class="dashicons dashicons-update awaiting"></i> Delete
								</button>
							</div>
						</div>
						<!-- Column Wrapper -->
						<div class="meow-section meow-group meow-cols">
							<!-- Left Column -->
							<div class="meow-col meow-span_1_of_2">
								<!-- Window -->
								<div class="meow-box">
									<h3><?php _e( "Collection (LR) → Post Type (WP)", 'wplr-sync' ) ?></h3>
									<div class="inside">
										<fieldset :disabled="isBusy(mapping)">
											<table class="form-table">
												<tr>
													<th scope="row">Post Type</th>
													<td>
														<select name="posttype" v-model="mapping.fields.posttype" style="width: 100%;">
															<option v-for="option in schema.posttype.options" :value="option.value">{{option.label}}</option>
														</select>
														<span class="description">Your collections in LR will be synchronized with this post type.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Status</th>
													<td>
														<select name="posttype_status" v-model="mapping.fields.posttype_status" style="width: 100%;">
															<option v-for="option in schema.posttype_status.options" :value="option.value">{{option.label}}</option>
														</select>
														<span class="description">Status of your post-type when it is created.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Reuse</th>
													<td>
														<label><input type="checkbox" name="posttype_reuse" v-model="mapping.fields.posttype_reuse"> Enable</label><br>
														<span class="description">If the name of your collection (LR) already matches the name of an existing post type, it will become associated with it instead of creating a new one.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Hierarchical</th>
													<td>
														<label>
															<input
																type="checkbox"
																name="posttype_hierarchical"
																v-model="mapping.fields.posttype_hierarchical"
																:disabled="!isHierarchical(mapping.fields.posttype)"
															>
															Enable
														</label><br>
														<span class="description">If your post type is hierarchical, with this option the hierarchy of collections will be made using the Post Type "{{mapping.fields.posttype}}".<br>Usage of taxonomies will be disabled.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Mode</th>
													<td>
														<select name="posttype_mode" v-model="mapping.fields.posttype_mode" style="width: 100%;">
															<option v-for="option in schema.posttype_mode.options" :value="option.value">{{option.label}}</option>
														</select>
														<span class="description">By default, it should be WP Gallery and native galleries will be created and maintained in your posts. For other modes, check the <a href="https://meowapps.com/wplr-sync-theme-assistant/">tutorial</a>.</span>
													</td>
												</tr>
												<tr v-if="showsPostMeta(mapping)">
													<th scope="row">Post Meta</th>
													<td>
														<input type="text" name="posttype_meta" v-model="mapping.fields.posttype_meta" required style="width: 260px;" /><br>
														<span class="description"><?php _e( 'The current chosen mode require the key of the <b>Post Meta</b> you would like the extension to update.', 'wplr-sync' ); ?></span>
													</td>
												</tr>
											</table>
										</fieldset>
									</div>
								</div><!-- /Window -->
							</div><!-- /Left Column -->
							<!-- Right Column -->
							<div class="meow-col meow-span_1_of_2">
								<!-- Window -->
								<div class="meow-box">
									<h3><?php _e( "Folder (LR) → Taxonomy (WP)", 'wplr-sync' ) ?></h3>
									<div class="inside">
										<fieldset :disabled="isBusy(mapping)">
											<table class="form-table">
												<tr>
													<th scope="row">Taxonomy</th>
													<td>
														<select name="taxonomy" v-model="mapping.fields.taxonomy" :disabled="!taxonomies(mapping.fields.posttype).length" style="width: 100%;">
															<option value="">-</option>
															<option v-for="option in taxonomies(mapping.fields.posttype)" :value="option.value">{{option.label}}</option>
														</select>
														<span class="description">Your folders (LR) will be synchronized with the terms in this taxonomy.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Reuse</th>
													<td>
														<label><input type="checkbox" name="taxonomy_reuse" v-model="mapping.fields.taxonomy_reuse" :disabled="!taxonomies(mapping.fields.posttype).length"> Enable</label><br>
														<span class="description">If the name of your folder (LR) already matches the name of an existing term (of your taxonomy), it will become associated with it instead of creating a new one.</span>
													</td>
												</tr>
											</table>
										</fieldset>
									</div>
								</div><!-- /Window -->
								<!-- Window -->
								<div class="meow-box">
									<h3><?php _e( "Keywords (LR) → Taxonomy (WP)", 'wplr-sync' ) ?></h3>
									<div class="inside">
										<fieldset :disabled="isBusy(mapping)">
											<table class="form-table">
												<tr>
													<th scope="row">Taxonomy</th>
													<td>
														<select name="taxonomy_tags" v-model="mapping.fields.taxonomy_tags" :disabled="!taxonomies(mapping.fields.posttype).length" style="width: 100%;">
															<option value="">-</option>
															<option v-for="option in taxonomies(mapping.fields.posttype)" :value="option.value">{{option.label}}</option>
														</select>
														<span class="description">Your keywords (LR) will be synchronized with the terms in this taxonomy.</span>
													</td>
												</tr>
												<tr>
													<th scope="row">Reuse</th>
													<td>
														<label><input type="checkbox" name="taxonomy_tags_reuse" v-model="mapping.fields.taxonomy_tags_reuse" :disabled="!taxonomies(mapping.fields.posttype).length"> Enable</label><br>
														<span class="description">If the name of your keyword (LR) already matches the name of an existing term (of your taxonomy), it will become associated with it instead of creating a new one.</span>
													</td>
												</tr>
											</table>
										</fieldset>
									</div>
								</div><!-- /Window -->
							</div><!-- /Right Column -->
						</div><!-- /Column Wrapper -->
					</form>
				</v-tab>
			</vue-tabs>
		</div>
	</div><!-- /Vue Component -->
</div>
