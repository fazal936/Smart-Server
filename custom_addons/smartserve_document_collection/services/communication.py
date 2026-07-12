"""Communication provider abstraction for SmartServe notifications."""

from typing import Protocol

from odoo import _
from odoo.exceptions import UserError


class CommunicationProvider(Protocol):
    code: str

    def send_initial_request(self, request_record) -> None:
        ...


class WhatsAppCommunicationProvider:
    code = 'whatsapp'

    def __init__(self, env):
        self.env = env

    def _is_configured(self) -> bool:
        config = self.env['ir.config_parameter'].sudo()
        return bool(
            config.get_param('smartserve.whatsapp.access_token')
            and config.get_param('smartserve.whatsapp.phone_number_id')
        )

    def send_initial_request(self, request_record) -> None:
        if not self._is_configured():
            request_record.communication_status = 'not_configured'
            request_record._log_activity(
                'communication_not_configured',
                _('WhatsApp provider is not configured. Staff must send the link manually.'),
            )
            return
        # Meta WhatsApp Business Platform send call belongs here.
        request_record.communication_status = 'queued'
        request_record._log_activity('whatsapp_queued', _('WhatsApp request message queued.'))


def get_communication_provider(env) -> CommunicationProvider:
    provider_code = env['ir.config_parameter'].sudo().get_param(
        'smartserve.communication.provider',
        'whatsapp',
    )
    if provider_code == 'whatsapp':
        return WhatsAppCommunicationProvider(env)
    raise UserError(_('Unsupported communication provider: %s') % provider_code)
