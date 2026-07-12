import hashlib
import os
from datetime import timedelta

from odoo import fields, http, _
from odoo.exceptions import UserError
from odoo.http import request

from ..services.storage import get_storage_provider


class SmartServeDocumentUploadController(http.Controller):

    @http.route('/upload/<string:token>', type='http', auth='public', methods=['GET', 'POST'], csrf=True)
    def upload_documents(self, token, **post):
        document_request = request.env['smartserve.document.request'].sudo().search([
            ('upload_token', '=', token),
        ], limit=1)

        if not document_request or not self._is_https_or_localhost() or not document_request.is_upload_link_usable():
            self._log_attempt(document_request, token, 'invalid', 'Invalid or unavailable upload link.')
            return request.render('smartserve_document_collection.upload_invalid_page', {
                'reason': self._invalid_reason(document_request),
            })

        if self._is_rate_limited(token):
            self._log_attempt(document_request, token, 'blocked', 'Upload request blocked by rate limit.')
            return request.render('smartserve_document_collection.upload_invalid_page', {
                'reason': _('Too many upload attempts. Please try again later or contact staff.'),
            })

        if request.httprequest.method == 'POST':
            document_request._log_activity('upload_started', _('Customer started upload submission.'))
            result = self._save_uploaded_files(document_request)
            if result['errors']:
                self._log_attempt(document_request, token, 'validation_error', '; '.join(result['errors']))
                return request.render('smartserve_document_collection.upload_page', {
                    'document_request': document_request,
                    'error': '; '.join(result['errors']),
                })
            if result['uploaded_count']:
                document_request.write({'state': 'submitted'})
                document_request._log_activity(
                    'upload_completed',
                    _('%s file(s) uploaded through secure link.') % result['uploaded_count'],
                )
                self._log_attempt(document_request, token, 'success', 'Files uploaded successfully.')
                return request.render('smartserve_document_collection.upload_success_page', {
                    'document_request': document_request,
                    'uploaded_count': result['uploaded_count'],
                })

            self._log_attempt(document_request, token, 'validation_error', 'No files selected.')
            return request.render('smartserve_document_collection.upload_page', {
                'document_request': document_request,
                'error': _('Please choose at least one file before submitting.'),
            })

        self._log_attempt(document_request, token, 'view', 'Upload page viewed.')
        return request.render('smartserve_document_collection.upload_page', {
            'document_request': document_request,
            'error': False,
        })

    def _save_uploaded_files(self, document_request):
        uploaded_count = 0
        errors = []
        storage_provider = get_storage_provider(request.env)

        for requirement in document_request.required_document_ids:
            files = request.httprequest.files.getlist(f'document_{requirement.id}')
            for file_storage in files:
                if not file_storage or not file_storage.filename:
                    continue

                validation_error = self._validate_file(requirement, file_storage)
                if validation_error:
                    errors.append(validation_error)
                    continue

                file_storage.stream.seek(0, os.SEEK_END)
                size = file_storage.stream.tell()
                file_storage.stream.seek(0)
                try:
                    stored_file = storage_provider.upload_file(
                        document_request,
                        requirement,
                        file_storage.filename,
                        file_storage.mimetype,
                        file_storage.stream,
                        size,
                    )
                except UserError as error:
                    errors.append(str(error))
                    continue
                request.env['smartserve.uploaded.document'].sudo().create({
                    'name': stored_file.filename,
                    'request_id': document_request.id,
                    'required_document_id': requirement.id,
                    'storage_provider': stored_file.provider,
                    'storage_file_id': stored_file.file_id,
                    'storage_url': stored_file.url,
                    'filename': stored_file.filename,
                    'mimetype': stored_file.mimetype,
                    'file_size': stored_file.size,
                })
                requirement.sudo().write({
                    'upload_status': 'uploaded',
                    'review_status': 'pending',
                    'sharepoint_file_id': stored_file.file_id,
                    'sharepoint_file_url': stored_file.url,
                })
                uploaded_count += 1

        return {'uploaded_count': uploaded_count, 'errors': errors}

    def _validate_file(self, requirement, file_storage):
        filename = file_storage.filename or ''
        extension = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''
        allowed_extensions = [
            item.strip().lower().lstrip('.')
            for item in (requirement.allowed_extensions or '').split(',')
            if item.strip()
        ]
        if allowed_extensions and extension not in allowed_extensions:
            return _('%s has an invalid file type. Allowed: %s') % (
                filename,
                ', '.join(allowed_extensions),
            )

        file_storage.stream.seek(0, os.SEEK_END)
        size = file_storage.stream.tell()
        file_storage.stream.seek(0)
        max_size = (requirement.max_size_mb or 0) * 1024 * 1024
        if max_size and size > max_size:
            return _('%s is too large. Maximum size is %s MB.') % (filename, requirement.max_size_mb)

        return False

    def _is_rate_limited(self, token):
        config = request.env['ir.config_parameter'].sudo()
        max_attempts = int(config.get_param('smartserve.upload_rate_limit_attempts', '30'))
        window_minutes = int(config.get_param('smartserve.upload_rate_limit_minutes', '15'))
        since = fields.Datetime.now() - timedelta(minutes=window_minutes)
        attempt_count = request.env['smartserve.upload.attempt'].sudo().search_count([
            ('token_hash', '=', self._token_hash(token)),
            ('ip_address', '=', self._remote_addr()),
            ('create_date', '>=', since),
        ])
        return attempt_count >= max_attempts

    def _log_attempt(self, document_request, token, result, summary):
        request.env['smartserve.upload.attempt'].sudo().create({
            'request_id': document_request.id if document_request else False,
            'token_hash': self._token_hash(token),
            'ip_address': self._remote_addr(),
            'user_agent': request.httprequest.headers.get('User-Agent'),
            'result': result,
            'summary': summary,
        })

    def _token_hash(self, token):
        return hashlib.sha256((token or '').encode('utf-8')).hexdigest()

    def _remote_addr(self):
        return request.httprequest.headers.get('X-Forwarded-For', request.httprequest.remote_addr or '').split(',')[0].strip()

    def _is_https_or_localhost(self):
        http_request = request.httprequest
        host = http_request.host.split(':')[0]
        return http_request.scheme == 'https' or host in ('localhost', '127.0.0.1', '0.0.0.0')

    def _invalid_reason(self, document_request):
        if not document_request:
            return _('This upload link is invalid.')
        if not self._is_https_or_localhost():
            return _('This upload link is only available over HTTPS.')
        if document_request.upload_expires_at < fields.Datetime.now():
            return _('This upload link has expired.')
        if document_request.state == 'draft':
            return _('This upload link has not been activated yet. Staff must click Generate Request first.')
        if document_request.state == 'rejected':
            return _('This request has been rejected and is no longer accepting uploads.')
        if document_request.upload_revoked or document_request.state in ('completed', 'revoked'):
            return _('This upload request is no longer available.')
        if not document_request.allow_multiple_uploads and document_request.state != 'waiting':
            return _('This upload link has already been used.')
        return _('This upload link is not available.')
