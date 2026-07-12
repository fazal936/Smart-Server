from odoo import http
from odoo.http import request
from odoo.tools import html_escape


class AltamkeenWebsiteServices(http.Controller):

    @http.route('/altamkeen/contact-request', type='http', auth='public', website=True, methods=['POST'], csrf=True)
    def contact_request(self, **post):
        name = (post.get('name') or '').strip()
        phone = (post.get('phone') or '').strip()
        email = (post.get('email') or '').strip()
        service = (post.get('service') or '').strip()
        message = (post.get('message') or '').strip()

        body = f"""
            <p><strong>Name:</strong> {html_escape(name)}</p>
            <p><strong>Phone:</strong> {html_escape(phone)}</p>
            <p><strong>Email:</strong> {html_escape(email)}</p>
            <p><strong>Service:</strong> {html_escape(service)}</p>
            <p><strong>Message:</strong><br/>{html_escape(message)}</p>
        """
        request.env['mail.mail'].sudo().create({
            'subject': f"Website consultation request - {name or 'New enquiry'}",
            'email_to': 'info@altamkeen.ae',
            'email_from': email or 'info@altamkeen.ae',
            'body_html': body,
        })
        return request.redirect('/?contact_sent=1#contact')

    @http.route('/services/business-setup', type='http', auth='public', website=True)
    def business_setup(self, **kwargs):
        return request.render('altamkeen_website.service_business_setup')

    @http.route('/services/pro-services', type='http', auth='public', website=True)
    def pro_services(self, **kwargs):
        return request.render('altamkeen_website.service_pro_services')

    @http.route('/services/visa-services', type='http', auth='public', website=True)
    def visa_services(self, **kwargs):
        return request.render('altamkeen_website.service_visa_services')

    @http.route('/services/document-attestation', type='http', auth='public', website=True)
    def document_attestation(self, **kwargs):
        return request.render('altamkeen_website.service_document_attestation')

    @http.route('/services/real-estate-investment', type='http', auth='public', website=True)
    def real_estate_investment(self, **kwargs):
        return request.render('altamkeen_website.service_real_estate_investment')

    @http.route('/services/business-advisory', type='http', auth='public', website=True)
    def business_advisory(self, **kwargs):
        return request.render('altamkeen_website.service_business_advisory')

    @http.route('/services/hr-consultancy', type='http', auth='public', website=True)
    def hr_consultancy(self, **kwargs):
        return request.render('altamkeen_website.service_hr_consultancy')

    @http.route('/services/global-immigration', type='http', auth='public', website=True)
    def global_immigration(self, **kwargs):
        return request.render('altamkeen_website.service_global_immigration')

    @http.route('/services/travel-tourism', type='http', auth='public', website=True)
    def travel_tourism(self, **kwargs):
        return request.render('altamkeen_website.service_travel_tourism')

    @http.route('/services/insurance-services', type='http', auth='public', website=True)
    def insurance_services(self, **kwargs):
        return request.render('altamkeen_website.service_insurance_services')

    @http.route('/services/driving-vehicle-services', type='http', auth='public', website=True)
    def driving_vehicle_services(self, **kwargs):
        return request.render('altamkeen_website.service_driving_vehicle_services')
