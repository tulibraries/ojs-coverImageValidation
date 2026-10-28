{extends file="layouts/backend.tpl"}

{block name="page"}
	<pkp-header>
		<h1>{translate key="plugins.generic.coverImageValidation.reviewCoverImages"}</h1>
	</pkp-header>

	<div class="pkp_coverImageValidationReviewPage">
		<p>{translate key="plugins.generic.coverImageValidation.reviewCoverImagesDescription"}</p>

		{if $coverReviewError}
			<div class="pkp_notification pkp_notification_error">
				{translate key=$coverReviewError}
			</div>
		{/if}

		{if !$coverReviewCandidates}
			<div class="pkp_notification">
				{translate key="plugins.generic.coverImageValidation.noCoverProposals"}
			</div>
		{else}
			{foreach from=$coverReviewCandidates item=candidate}
				<section class="pkp_coverImageValidationCandidate">
					<h2>{$candidate.name|escape}</h2>
					<p>{$candidate.mime|escape}</p>
					<p><img src="{$candidate.previewUrl|escape}" alt="{$candidate.name|escape}" style="max-width: 500px; max-height: 500px;" /></p>

					<form method="post" action="{$candidate.approveUrl|escape}">
						{csrf}
						<input type="hidden" name="op" value="approve" />
						<input type="hidden" name="submissionId" value="{$coverReviewSubmission->getId()}" />
						<input type="hidden" name="publicationId" value="{$coverReviewPublication->getId()}" />
						<input type="hidden" name="fileId" value="{$candidate.id}" />
						<label>
							{translate key="plugins.generic.coverImageValidation.coverLocale"}
							<select name="locale">
								{foreach from=$coverReviewLocales key=locale item=localeName}
									<option value="{$locale|escape}"{if $locale == $coverReviewPrimaryLocale} selected{/if}>{$localeName|escape}</option>
								{/foreach}
							</select>
						</label>
						<label>
							{translate key="plugins.generic.coverImageValidation.coverAltText"}
							<input type="text" name="altText" value="{$candidate.altText|escape}" maxlength="255" />
						</label>
						<button type="submit" class="pkp_button">{translate key="plugins.generic.coverImageValidation.approveCover"}</button>
					</form>

					<form method="post" action="{$candidate.rejectUrl|escape}" style="display: inline-block; margin-top: 0.5rem;">
						{csrf}
						<input type="hidden" name="op" value="reject" />
						<input type="hidden" name="submissionId" value="{$coverReviewSubmission->getId()}" />
						<input type="hidden" name="publicationId" value="{$coverReviewPublication->getId()}" />
						<input type="hidden" name="fileId" value="{$candidate.id}" />
						<button type="submit" class="pkp_button">{translate key="plugins.generic.coverImageValidation.rejectCover"}</button>
					</form>
				</section>
			{/foreach}
		{/if}
	</div>
{/block}
