"""Storage provider abstraction for SmartServe document files.

Business models and controllers should talk to this module, not directly to
SharePoint or any future storage backend. Uploaded file bytes must not be
stored in PostgreSQL.
"""

import os
import shutil
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import BinaryIO, Protocol

from odoo import _
from odoo.exceptions import UserError


@dataclass(frozen=True)
class StoredFile:
    provider: str
    file_id: str
    url: str
    filename: str
    mimetype: str
    size: int


class StorageProvider(Protocol):
    code: str

    def create_request_folder(self, request_record) -> str:
        ...

    def upload_file(
        self,
        request_record,
        requirement,
        filename: str,
        mimetype: str,
        stream: BinaryIO,
        size: int,
    ) -> StoredFile:
        ...


class SharePointStorageProvider:
    code = 'sharepoint'

    def __init__(self, env):
        self.env = env

    def _is_configured(self) -> bool:
        config = self.env['ir.config_parameter'].sudo()
        required_keys = [
            'smartserve.sharepoint.tenant_id',
            'smartserve.sharepoint.client_id',
            'smartserve.sharepoint.client_secret',
            'smartserve.sharepoint.site_id',
            'smartserve.sharepoint.drive_id',
        ]
        return all(config.get_param(key) for key in required_keys)

    def create_request_folder(self, request_record) -> str:
        if not self._is_configured():
            request_record._log_activity(
                'storage_not_configured',
                _(
                    'SharePoint storage is not configured. The upload link can be tested, '
                    'but file submission will require Microsoft Graph configuration.'
                ),
            )
            return request_record.sharepoint_folder_id or f"pending-sharepoint-{request_record.name}"
        # Microsoft Graph folder creation will be implemented behind this method.
        # The rest of SmartServe should not change when this becomes a live call.
        return request_record.sharepoint_folder_id or request_record.name

    def upload_file(
        self,
        request_record,
        requirement,
        filename: str,
        mimetype: str,
        stream: BinaryIO,
        size: int,
    ) -> StoredFile:
        if not self._is_configured():
            raise UserError(_(
                'SharePoint storage is not configured. Uploaded files are not '
                'stored in Odoo; configure SharePoint before accepting uploads.'
            ))
        # Microsoft Graph upload will be implemented here. Returning metadata keeps
        # the controller/model contract stable for future providers.
        file_id = f"{request_record.name}/{requirement.id}/{filename}"
        return StoredFile(
            provider=self.code,
            file_id=file_id,
            url='',
            filename=filename,
            mimetype=mimetype,
            size=size,
        )


class LocalStorageProvider:
    """Development storage provider.

    This keeps uploaded file bytes on the server filesystem, not in PostgreSQL.
    It exists so the upload workflow can be tested before Microsoft Graph is
    configured. Production should use the SharePoint provider.
    """

    code = 'local'

    def __init__(self, env):
        self.env = env

    def _base_path(self) -> Path:
        config = self.env['ir.config_parameter'].sudo()
        return Path(config.get_param(
            'smartserve.local_storage.path',
            '/var/lib/odoo/smartserve_uploads',
        ))

    def create_request_folder(self, request_record) -> str:
        folder_id = request_record.sharepoint_folder_id or self._safe_folder_name(request_record.name)
        folder_path = self._base_path() / folder_id
        folder_path.mkdir(parents=True, exist_ok=True)
        request_record._log_activity(
            'local_storage_folder_ready',
            _('Local development storage folder prepared.'),
        )
        return folder_id

    def upload_file(
        self,
        request_record,
        requirement,
        filename: str,
        mimetype: str,
        stream: BinaryIO,
        size: int,
    ) -> StoredFile:
        folder_id = request_record.sharepoint_folder_id or self.create_request_folder(request_record)
        safe_filename = self._safe_filename(filename)
        file_id = f"{requirement.id}-{uuid.uuid4().hex}-{safe_filename}"
        target_path = self._base_path() / folder_id / file_id
        target_path.parent.mkdir(parents=True, exist_ok=True)
        with target_path.open('wb') as output:
            shutil.copyfileobj(stream, output)
        return StoredFile(
            provider=self.code,
            file_id=str(target_path),
            url='',
            filename=filename,
            mimetype=mimetype,
            size=size,
        )

    def _safe_folder_name(self, value: str) -> str:
        return ''.join(char if char.isalnum() or char in ('-', '_') else '-' for char in value or 'request')

    def _safe_filename(self, value: str) -> str:
        basename = os.path.basename(value or 'upload')
        return ''.join(char if char.isalnum() or char in ('-', '_', '.') else '-' for char in basename)


def get_storage_provider(env) -> StorageProvider:
    provider_code = env['ir.config_parameter'].sudo().get_param(
        'smartserve.storage.provider',
        'local',
    )
    if provider_code == 'local':
        return LocalStorageProvider(env)
    if provider_code == 'sharepoint':
        return SharePointStorageProvider(env)
    raise UserError(_('Unsupported storage provider: %s') % provider_code)
