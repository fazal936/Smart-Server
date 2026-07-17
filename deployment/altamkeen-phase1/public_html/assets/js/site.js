document.addEventListener("DOMContentLoaded",()=>{
  document.querySelectorAll("[data-year]").forEach(el=>el.textContent=new Date().getFullYear());
  document.querySelectorAll('input[name="form_loaded"]').forEach(el=>el.value=Math.floor(Date.now()/1000));
  const params=new URLSearchParams(location.search), status=params.get("status"), box=document.querySelector("[data-form-status]");
  const messages={success:["success","Thank you. Your request has been sent and our team will contact you shortly."],invalid:["error","Please review the required fields and submit the form again."],spam:["error","We could not accept this submission. Please wait a moment and try again."],rate:["error","Please wait before sending another request, or contact us by phone or WhatsApp."],mail:["error","Your request could not be emailed. Please contact us by phone or WhatsApp."],error:["error","Something went wrong. Please try again or use WhatsApp."]};
  if(box&&status&&messages[status]){box.classList.add("show",messages[status][0]);box.textContent=messages[status][1];box.setAttribute("role",status==="success"?"status":"alert");box.scrollIntoView({block:"center"});history.replaceState({},"",location.pathname+location.hash)}
  document.querySelectorAll('form[action="/contact-submit.php"]').forEach(form=>form.addEventListener("submit",()=>{
    const button=form.querySelector('button[type="submit"]');
    if(button){button.disabled=true;button.textContent="Sending..."}
  }));
  const serviceDetails=[...document.querySelectorAll("details.detail-card")];
  const serviceLinks=[...document.querySelectorAll(".service-jump a")];
  const markSelectedService=id=>serviceLinks.forEach(link=>{
    const selected=link.hash===`#${id}`;
    link.classList.toggle("active",selected);
    if(selected)link.setAttribute("aria-current","true");else link.removeAttribute("aria-current");
  });
  const openSelectedService=()=>{
    if(!location.hash||!serviceDetails.length)return;
    const selected=document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if(!selected||!selected.matches("details.detail-card"))return;
    serviceDetails.forEach(detail=>detail.open=detail===selected);
    markSelectedService(selected.id);
    requestAnimationFrame(()=>requestAnimationFrame(()=>selected.scrollIntoView({block:"start"})));
  };
  serviceDetails.forEach(detail=>detail.addEventListener("toggle",()=>{
    if(detail.open){serviceDetails.forEach(other=>{if(other!==detail)other.open=false});markSelectedService(detail.id)}
  }));
  openSelectedService();
  window.addEventListener("hashchange",openSelectedService);
  const items=document.querySelectorAll(".section,.service-card,.detail-card");
  if(!("IntersectionObserver" in window)||matchMedia("(prefers-reduced-motion: reduce)").matches){items.forEach(el=>el.classList.add("visible"));return}
  items.forEach(el=>el.classList.add("reveal"));const io=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add("visible");io.unobserve(e.target)}}),{threshold:.08});items.forEach(el=>io.observe(el));
});
